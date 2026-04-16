<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Enums\Espace;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeDocumentPrevisionnel;
use App\Enums\TypeTransaction;
use App\Mail\DocumentMail;
use App\Models\CompteBancaire;
use App\Models\DocumentPrevisionnel;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\DocumentPrevisionnelService;
use App\Services\NumeroPieceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

final class ReglementTable extends Component
{
    public Operation $operation;

    public ?int $docModalParticipantId = null;

    public string $docModalType = 'devis';

    public string $docModalMessage = '';

    public string $docModalMessageType = 'info';

    public ?int $comptabiliserCompteId = null;

    public ?int $comptabiliserSeanceId = null;

    public bool $showComptabiliserModal = false;

    public function openDocModal(int $participantId, string $type): void
    {
        $this->docModalParticipantId = $participantId;
        $this->docModalType = $type;
        $this->docModalMessage = '';
    }

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false;
    }

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        $this->preFillFromTarif();
    }

    private function preFillFromTarif(): void
    {
        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        if ($seances->isEmpty()) {
            return;
        }

        $participants = $this->operation->participants()
            ->with('typeOperationTarif')
            ->get();

        foreach ($participants as $participant) {
            $tarif = $participant->typeOperationTarif?->montant;

            if ($tarif === null) {
                continue;
            }

            foreach ($seances as $seance) {
                $existing = Reglement::where('participant_id', $participant->id)
                    ->where('seance_id', $seance->id)
                    ->first();

                if ($existing !== null && $existing->montant_prevu !== null) {
                    continue;
                }

                Reglement::updateOrCreate(
                    ['participant_id' => $participant->id, 'seance_id' => $seance->id],
                    ['montant_prevu' => $tarif]
                );
            }
        }
    }

    public function cycleModePaiement(int $participantId, int $seanceId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);

        $reglement = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seance->id)
            ->first();

        if ($reglement?->remise_id !== null) {
            return;
        }

        $current = $reglement?->mode_paiement;
        $next = ModePaiement::nextReglementMode($current);

        Reglement::updateOrCreate(
            ['participant_id' => $participantId, 'seance_id' => $seance->id],
            ['mode_paiement' => $next]
        );
    }

    public function updateMontant(int $participantId, int $seanceId, string $montant): void
    {
        if (! $this->canEdit) {
            return;
        }

        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);

        $existing = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seance->id)
            ->first();

        if ($existing?->remise_id !== null) {
            return;
        }

        $parsed = (float) str_replace(',', '.', $montant);

        Reglement::updateOrCreate(
            ['participant_id' => $participantId, 'seance_id' => $seance->id],
            ['montant_prevu' => $parsed]
        );
    }

    public function copierLigne(int $participantId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        if ($seances->isEmpty()) {
            return;
        }

        $source = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seances->first()->id)
            ->first();

        if (! $source) {
            return;
        }

        foreach ($seances->skip(1) as $seance) {
            $existing = Reglement::where('participant_id', $participantId)
                ->where('seance_id', $seance->id)
                ->first();

            if ($existing?->remise_id !== null) {
                continue;
            }

            Reglement::updateOrCreate(
                ['participant_id' => $participantId, 'seance_id' => $seance->id],
                [
                    'mode_paiement' => $source->mode_paiement,
                    'montant_prevu' => $source->montant_prevu,
                ]
            );
        }
    }

    public function emettreDocument(int $participantId, string $type): void
    {
        if (! $this->canEdit) {
            return;
        }

        $participant = $this->operation->participants()->findOrFail($participantId);
        $typeEnum = TypeDocumentPrevisionnel::from($type);

        $service = app(DocumentPrevisionnelService::class);
        $document = $service->emettre($this->operation, $participant, $typeEnum);

        // Generate PDF if not already stored
        if (! $document->pdf_path) {
            $service->genererPdf($document);
        }

        $label = $typeEnum === TypeDocumentPrevisionnel::Devis ? 'Devis' : 'Pro forma';
        $this->docModalMessage = "{$label} v{$document->version} généré.";
        $this->docModalMessageType = 'success';

        $this->dispatch('open-url', url: route('operations.documents-previsionnels.pdf', $document));
    }

    public function marquerRecu(int $transactionId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $tx = Transaction::findOrFail($transactionId);

        if ($tx->statut_reglement !== StatutReglement::EnAttente) {
            return; // Already received or reconciled
        }

        if ($tx->isLockedByRapprochement() || $tx->isLockedByFacture()) {
            return;
        }

        $tx->update(['statut_reglement' => StatutReglement::Recu->value]);
    }

    public function ouvrirComptabiliser(int $seanceId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->comptabiliserSeanceId = $seanceId;
        $this->comptabiliserCompteId = CompteBancaire::where('est_systeme', false)
            ->where('actif_recettes_depenses', true)
            ->value('id');
        $this->showComptabiliserModal = true;
        $this->dispatch('comptabiliser-modal-open');
    }

    public function comptabiliserSeance(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->validate([
            'comptabiliserCompteId' => 'required|exists:comptes_bancaires,id',
            'comptabiliserSeanceId' => 'required|exists:seances,id',
        ]);

        $seance = Seance::with('operation.typeOperation')->findOrFail((int) $this->comptabiliserSeanceId);
        $operation = $seance->operation;
        $sousCategorieId = $operation->typeOperation?->sous_categorie_id;

        if ($sousCategorieId === null) {
            $this->addError('comptabiliserCompteId', "Le type d'opération n'a pas de sous-catégorie configurée.");

            return;
        }

        // Only process reglements WITHOUT an existing transaction
        $reglements = Reglement::with('participant.tiers')
            ->where('seance_id', (int) $this->comptabiliserSeanceId)
            ->where('montant_prevu', '>', 0)
            ->whereDoesntHave('transaction')
            ->get();

        if ($reglements->isEmpty()) {
            $this->showComptabiliserModal = false;
            $this->dispatch('comptabiliser-modal-close');

            return;
        }

        $sansMoyenPaiement = $reglements->filter(fn ($r) => $r->mode_paiement === null);
        if ($sansMoyenPaiement->isNotEmpty()) {
            $noms = $sansMoyenPaiement->map(fn ($r) => $r->participant->tiers->displayName())->join(', ');
            $this->addError('comptabiliserCompteId', "Moyen de paiement manquant pour : {$noms}.");

            return;
        }

        DB::transaction(function () use ($reglements, $seance, $operation, $sousCategorieId): void {
            foreach ($reglements as $reglement) {
                $tiers = $reglement->participant->tiers;
                $libelle = "Règlement {$tiers->displayName()} — {$operation->nom} S{$seance->numero}";

                $date = now();
                $tx = Transaction::create([
                    'type' => TypeTransaction::Recette->value,
                    'date' => $date->toDateString(),
                    'numero_piece' => app(NumeroPieceService::class)->assign($date),
                    'libelle' => $libelle,
                    'montant_total' => $reglement->montant_prevu,
                    'mode_paiement' => $reglement->mode_paiement?->value,
                    'tiers_id' => $tiers->id,
                    'compte_id' => (int) $this->comptabiliserCompteId,
                    'statut_reglement' => StatutReglement::EnAttente->value,
                    'reglement_id' => $reglement->id,
                    'saisi_par' => auth()->id(),
                ]);

                TransactionLigne::create([
                    'transaction_id' => $tx->id,
                    'sous_categorie_id' => $sousCategorieId,
                    'operation_id' => $operation->id,
                    'seance' => $seance->numero,
                    'montant' => $reglement->montant_prevu,
                ]);
            }
        });

        $this->showComptabiliserModal = false;
        $this->dispatch('comptabiliser-modal-close');
        $this->dispatch('$refresh');
    }

    public function render(): View
    {
        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        $participants = $this->operation->participants()
            ->with('tiers')
            ->get()
            ->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? '')))
            ->values();

        // Load all reglements in one query, indexed by "participantId-seanceId"
        $seanceIds = $seances->pluck('id');
        $reglements = Reglement::whereIn('seance_id', $seanceIds)->get();

        $reglementMap = [];
        foreach ($reglements as $r) {
            $reglementMap[$r->participant_id.'-'.$r->seance_id] = $r;
        }

        // Compute realized amounts from transaction_lignes
        $realiseMap = $this->computeRealise($seances, $participants);

        // Load existing documents for badge display
        $docVersions = DocumentPrevisionnel::where('operation_id', $this->operation->id)
            ->whereIn('participant_id', $participants->pluck('id'))
            ->select('participant_id', 'type', DB::raw('MAX(version) as last_version'), DB::raw('MAX(id) as last_id'))
            ->groupBy('participant_id', 'type')
            ->get()
            ->groupBy('participant_id')
            ->map(fn ($items) => $items->keyBy(fn ($item) => $item->type->value));

        // Determine which seances are fully comptabilisées (all their reglements have a transaction)
        $operationReglements = Reglement::whereIn('seance_id', $seanceIds)
            ->where('montant_prevu', '>', 0)
            ->get(['id', 'seance_id', 'participant_id']);

        $reglementIdsWithTx = Transaction::whereIn('reglement_id', $operationReglements->pluck('id'))
            ->whereNotNull('reglement_id')
            ->pluck('reglement_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $seanceComptabiliseeFlags = [];
        foreach ($seances as $seance) {
            $seanceReglements = $operationReglements->where('seance_id', $seance->id);
            if ($seanceReglements->isEmpty()) {
                $seanceComptabiliseeFlags[(int) $seance->id] = false;

                continue;
            }
            $allHaveTx = $seanceReglements->every(fn ($r) => in_array((int) $r->id, $reglementIdsWithTx, true));
            $seanceComptabiliseeFlags[(int) $seance->id] = $allHaveTx;
        }

        // Build transactionMap: "participantId-seanceId" => Transaction
        // Only for transactions linked via reglement_id (created by Comptabiliser)
        $txByReglement = Transaction::whereIn('reglement_id', $operationReglements->pluck('id'))
            ->get()
            ->keyBy(fn ($tx) => (int) $tx->reglement_id);

        $transactionMap = [];
        foreach ($operationReglements as $reglement) {
            $tx = $txByReglement->get((int) $reglement->id);
            if ($tx !== null) {
                $transactionMap[(int) $reglement->participant_id.'-'.(int) $reglement->seance_id] = $tx;
            }
        }

        // Available accounts for comptabilisation
        $comptesBancaires = CompteBancaire::where('est_systeme', false)
            ->where('actif_recettes_depenses', true)
            ->get();

        return view('livewire.reglement-table', [
            'seances' => $seances,
            'participants' => $participants,
            'reglementMap' => $reglementMap,
            'realiseMap' => $realiseMap,
            'docVersions' => $docVersions,
            'seanceComptabiliseeFlags' => $seanceComptabiliseeFlags,
            'comptesBancaires' => $comptesBancaires,
            'transactionMap' => $transactionMap,
        ]);
    }

    /**
     * @return array<string, float> keyed by "participantId-seanceId"
     */
    private function computeRealise(\Illuminate\Database\Eloquent\Collection $seances, Collection $participants): array
    {
        if ($seances->isEmpty() || $participants->isEmpty()) {
            return [];
        }

        $tiersIds = $participants->pluck('tiers_id')->unique()->values();
        $seanceNumeros = $seances->pluck('numero', 'id'); // id => numero

        $rows = DB::table('transaction_lignes')
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->where('transactions.type', 'recette')
            ->whereIn('transactions.tiers_id', $tiersIds)
            ->where('transaction_lignes.operation_id', $this->operation->id)
            ->whereNotNull('transaction_lignes.seance')
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_lignes.deleted_at')
            ->select(
                'transactions.tiers_id',
                'transaction_lignes.seance as seance_numero',
                DB::raw('SUM(transaction_lignes.montant) as total')
            )
            ->groupBy('transactions.tiers_id', 'transaction_lignes.seance')
            ->get();

        // Build tiers_id => participant_id mapping
        $tiersToParticipant = $participants->pluck('id', 'tiers_id');
        // Build seance numero => seance id mapping
        $numeroToSeanceId = $seanceNumeros->flip(); // numero => id

        $map = [];
        foreach ($rows as $row) {
            $participantId = $tiersToParticipant[$row->tiers_id] ?? null;
            $seanceId = $numeroToSeanceId[$row->seance_numero] ?? null;

            if ($participantId !== null && $seanceId !== null) {
                $map[$participantId.'-'.$seanceId] = (float) $row->total;
            }
        }

        return $map;
    }

    public function envoyerDocumentEmail(int $participantId, string $type): void
    {
        if (! $this->canEdit) {
            return;
        }

        $typeEnum = TypeDocumentPrevisionnel::from($type);

        $doc = DocumentPrevisionnel::where('participant_id', $participantId)
            ->where('operation_id', $this->operation->id)
            ->where('type', $typeEnum)
            ->orderByDesc('version')
            ->with(['participant.tiers'])
            ->first();

        if (! $doc) {
            $this->docModalMessage = 'Aucun document trouvé.';
            $this->docModalMessageType = 'danger';

            return;
        }

        $tiers = $doc->participant->tiers;

        if (! $tiers?->email) {
            $this->docModalMessage = 'Aucune adresse email pour ce tiers.';
            $this->docModalMessageType = 'danger';

            return;
        }

        $typeOp = $this->operation->typeOperation;
        if (! $typeOp?->effectiveEmailFrom()) {
            $this->docModalMessage = "Aucune adresse d'expédition configurée (ni sur le type d'opération, ni dans Paramètres > Association > Communication).";
            $this->docModalMessageType = 'danger';

            return;
        }

        [$typeLabel, $article, $articleDe] = match ($typeEnum) {
            TypeDocumentPrevisionnel::Devis => ['devis', 'le devis', 'du devis'],
            TypeDocumentPrevisionnel::Proforma => ['pro forma', 'la pro forma', 'de la pro forma'],
        };

        $pdfContent = $doc->pdf_path && Storage::disk('local')->exists($doc->pdf_path)
            ? Storage::disk('local')->get($doc->pdf_path)
            : app(DocumentPrevisionnelService::class)->genererPdf($doc);

        $pdfFilename = ucfirst($typeLabel)." {$doc->numero} - {$tiers->displayName()}.pdf";

        $template = EmailTemplate::where('categorie', CategorieEmail::Document->value)
            ->whereNull('type_operation_id')
            ->first();

        try {
            $mail = new DocumentMail(
                prenomDestinataire: $tiers->prenom ?? '',
                nomDestinataire: $tiers->nom,
                typeDocument: $typeLabel,
                typeDocumentArticle: $article,
                typeDocumentArticleDe: $articleDe,
                numeroDocument: $doc->numero,
                dateDocument: $doc->date->format('d/m/Y'),
                montantTotal: number_format((float) $doc->montant_total, 2, ',', "\u{00A0}").' €',
                customObjet: $template?->objet,
                customCorps: $template?->corps,
                pdfContent: $pdfContent,
                pdfFilename: $pdfFilename,
                typeOperationId: $this->operation->type_operation_id,
            );

            Mail::mailer()
                ->to($tiers->email)
                ->send($mail->from($typeOp->effectiveEmailFrom(), $typeOp->effectiveEmailFromName()));

            EmailLog::create([
                'tiers_id' => $tiers->id,
                'participant_id' => $doc->participant_id,
                'operation_id' => $doc->operation_id,
                'categorie' => CategorieEmail::Document->value,
                'email_template_id' => $template?->id,
                'destinataire_email' => $tiers->email,
                'destinataire_nom' => $tiers->displayName(),
                'objet' => $mail->envelope()->subject,
                'statut' => 'envoye',
                'envoye_par' => Auth::id(),
            ]);

            $this->docModalMessage = ucfirst($typeLabel)." envoyé à {$tiers->email}.";
            $this->docModalMessageType = 'success';
        } catch (\Throwable $e) {
            EmailLog::create([
                'tiers_id' => $tiers->id,
                'participant_id' => $doc->participant_id,
                'operation_id' => $doc->operation_id,
                'categorie' => CategorieEmail::Document->value,
                'email_template_id' => $template?->id,
                'destinataire_email' => $tiers->email,
                'destinataire_nom' => $tiers->displayName(),
                'objet' => ucfirst($typeLabel).' '.$doc->numero,
                'statut' => 'erreur',
                'erreur_message' => $e->getMessage(),
                'envoye_par' => Auth::id(),
            ]);

            $this->docModalMessage = "Erreur lors de l'envoi : ".$e->getMessage();
            $this->docModalMessageType = 'danger';
        }
    }
}
