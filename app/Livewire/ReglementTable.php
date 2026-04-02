<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Enums\ModePaiement;
use App\Enums\TypeDocumentPrevisionnel;
use App\Mail\DocumentMail;
use App\Models\DocumentPrevisionnel;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Reglement;
use App\Models\Seance;
use App\Services\DocumentPrevisionnelService;
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

    public function openDocModal(int $participantId, string $type): void
    {
        $this->docModalParticipantId = $participantId;
        $this->docModalType = $type;
        $this->docModalMessage = '';
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

        $this->dispatch('open-url', url: route('gestion.documents-previsionnels.pdf', $document));
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

        return view('livewire.reglement-table', [
            'seances' => $seances,
            'participants' => $participants,
            'reglementMap' => $reglementMap,
            'realiseMap' => $realiseMap,
            'docVersions' => $docVersions,
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
        if (! $typeOp?->email_from) {
            $this->docModalMessage = "L'adresse d'expédition n'est pas configurée pour le type d'opération.";
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

        $pdfFilename = ucfirst($typeLabel) . " {$doc->numero} - {$tiers->displayName()}.pdf";

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
                montantTotal: number_format((float) $doc->montant_total, 2, ',', "\u{00A0}") . ' €',
                customObjet: $template?->objet,
                customCorps: $template?->corps,
                pdfContent: $pdfContent,
                pdfFilename: $pdfFilename,
                typeOperationId: $this->operation->type_operation_id,
            );

            Mail::mailer()
                ->to($tiers->email)
                ->send($mail->from($typeOp->email_from, $typeOp->email_from_name));

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

            $this->docModalMessage = ucfirst($typeLabel) . " envoyé à {$tiers->email}.";
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
                'objet' => ucfirst($typeLabel) . ' ' . $doc->numero,
                'statut' => 'erreur',
                'erreur_message' => $e->getMessage(),
                'envoye_par' => Auth::id(),
            ]);

            $this->docModalMessage = "Erreur lors de l'envoi : " . $e->getMessage();
            $this->docModalMessageType = 'danger';
        }
    }
}
