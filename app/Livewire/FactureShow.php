<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Mail\DocumentMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Services\FactureService;
use App\Support\CurrentAssociation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

final class FactureShow extends Component
{
    public Facture $facture;

    /** @var array<int> */
    public array $selectedTransactionIds = [];

    // ── Email state ──
    public string $emailMessage = '';

    public string $emailMessageType = 'info';

    public bool $showEmailSenderModal = false;

    /** @var array<int, array{email: string, label: string}> */
    public array $emailSenderChoices = [];

    public ?string $selectedEmailFrom = null;

    public ?string $selectedEmailFromName = null;

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function mount(Facture $facture): void
    {
        if ($facture->statut === StatutFacture::Brouillon) {
            $this->redirect(route('facturation.factures.edit', $facture));

            return;
        }

        $facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions.compte']);
        $this->facture = $facture;
    }

    public function toggleTransaction(int $id): void
    {
        if (in_array($id, $this->selectedTransactionIds, true)) {
            $this->selectedTransactionIds = array_values(array_diff($this->selectedTransactionIds, [$id]));
        } else {
            $this->selectedTransactionIds[] = $id;
        }
    }

    public function enregistrerReglement(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (count($this->selectedTransactionIds) === 0) {
            session()->flash('error', 'Veuillez sélectionner au moins une transaction.');

            return;
        }

        try {
            app(FactureService::class)->marquerReglementRecu(
                $this->facture,
                $this->selectedTransactionIds,
            );

            $this->selectedTransactionIds = [];
            $this->facture->load(['transactions.compte']);

            session()->flash('success', 'Règlement enregistré.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function annuler(): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(FactureService::class)->annuler($this->facture);
            $this->facture->refresh();
            $this->facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions.compte']);
            session()->flash('success', "Avoir {$this->facture->numero_avoir} émis. La facture {$this->facture->numero} est annulée.");
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function envoyerEmail(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->emailMessage = '';

        $tiers = $this->facture->tiers;
        if (! $tiers?->email) {
            $this->emailMessage = 'Aucune adresse email pour ce tiers.';
            $this->emailMessageType = 'danger';

            return;
        }

        // Résoudre les expéditeurs possibles via les opérations liées
        $senders = $this->resolveEmailSenders();

        if ($senders->isEmpty()) {
            $this->emailMessage = "Aucune adresse d'expédition configurée. Configurez l'email dans le type d'opération.";
            $this->emailMessageType = 'danger';

            return;
        }

        if ($senders->count() === 1) {
            $sender = $senders->first();
            $this->doSendEmail($sender['email'], $sender['name']);

            return;
        }

        // Plusieurs expéditeurs → modale de choix
        $this->emailSenderChoices = $senders->values()->all();
        $this->selectedEmailFrom = $senders->first()['email'];
        $this->selectedEmailFromName = $senders->first()['name'];
        $this->showEmailSenderModal = true;
    }

    public function confirmSendEmail(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (! $this->selectedEmailFrom) {
            return;
        }

        $this->showEmailSenderModal = false;
        $this->doSendEmail($this->selectedEmailFrom, $this->selectedEmailFromName);
    }

    public function render(): View
    {
        $montantRegle = $this->facture->montantRegle();
        $isAcquittee = $this->facture->isAcquittee();

        $transactionsEnAttente = $this->facture->transactions
            ->filter(fn ($t) => $t->statut_reglement === StatutReglement::EnAttente);

        // Opérations liées via transactions
        $operationIds = TransactionLigne::whereIn(
            'transaction_id',
            $this->facture->transactions->pluck('id')
        )->whereNotNull('operation_id')
            ->distinct()
            ->pluck('operation_id');

        $operationsLiees = $operationIds->isNotEmpty()
            ? Operation::whereIn('id', $operationIds)->orderBy('nom')->get()
            : collect();

        return view('livewire.facture-show', [
            'montantRegle' => $montantRegle,
            'isAcquittee' => $isAcquittee,
            'transactionsEnAttente' => $transactionsEnAttente,
            'operationsLiees' => $operationsLiees,
        ]);
    }

    private function doSendEmail(string $fromEmail, ?string $fromName): void
    {
        $tiers = $this->facture->tiers;
        $template = EmailTemplate::where('categorie', CategorieEmail::Document->value)
            ->whereNull('type_operation_id')
            ->first();

        $pdfContent = app(FactureService::class)->genererPdf($this->facture);
        $label = $this->facture->numero ?? 'Brouillon';
        $pdfFilename = "Facture {$label} - {$tiers->displayName()}.pdf";

        // Résoudre opération et participant pour la timeline
        $operationId = TransactionLigne::whereIn(
            'transaction_id',
            $this->facture->transactions()->pluck('transactions.id')
        )->whereNotNull('operation_id')->value('operation_id');

        $participantId = $operationId
            ? Participant::where('tiers_id', $tiers->id)
                ->where('operation_id', $operationId)
                ->value('id')
            : null;

        try {
            $mail = new DocumentMail(
                prenomDestinataire: $tiers->prenom ?? '',
                nomDestinataire: $tiers->nom,
                typeDocument: 'facture',
                typeDocumentArticle: 'la facture',
                typeDocumentArticleDe: 'de la facture',
                numeroDocument: $label,
                dateDocument: $this->facture->date->format('d/m/Y'),
                montantTotal: number_format((float) $this->facture->montant_total, 2, ',', "\u{00A0}").' €',
                customObjet: $template?->objet,
                customCorps: $template?->corps,
                pdfContent: $pdfContent,
                pdfFilename: $pdfFilename,
                typeOperationId: $this->resolveFirstTypeOperationId(),
            );

            Mail::mailer()
                ->to($tiers->email)
                ->send($mail->from($fromEmail, $fromName));

            EmailLog::create([
                'tiers_id' => $tiers->id,
                'participant_id' => $participantId,
                'operation_id' => $operationId,
                'categorie' => CategorieEmail::Document->value,
                'email_template_id' => $template?->id,
                'destinataire_email' => $tiers->email,
                'destinataire_nom' => $tiers->displayName(),
                'objet' => $mail->envelope()->subject,
                'statut' => 'envoye',
                'envoye_par' => Auth::id(),
            ]);

            $this->emailMessage = "Facture envoyée à {$tiers->email}.";
            $this->emailMessageType = 'success';
        } catch (\Throwable $e) {
            EmailLog::create([
                'tiers_id' => $tiers->id,
                'participant_id' => $participantId,
                'operation_id' => $operationId,
                'categorie' => CategorieEmail::Document->value,
                'email_template_id' => $template?->id,
                'destinataire_email' => $tiers->email,
                'destinataire_nom' => $tiers->displayName(),
                'objet' => 'Facture '.$label,
                'statut' => 'erreur',
                'erreur_message' => $e->getMessage(),
                'envoye_par' => Auth::id(),
            ]);

            $this->emailMessage = 'Erreur lors de l\'envoi : '.$e->getMessage();
            $this->emailMessageType = 'danger';
        }
    }

    /**
     * @return Collection<int, array{email: string, name: ?string, label: string}>
     */
    private function resolveEmailSenders(): Collection
    {
        // Opérations liées via facture → transactions → transaction_lignes → operation_id
        $operationIds = TransactionLigne::whereIn(
            'transaction_id',
            $this->facture->transactions()->pluck('transactions.id')
        )
            ->whereNotNull('operation_id')
            ->distinct()
            ->pluck('operation_id');

        $typeOperationIds = Operation::whereIn('id', $operationIds)
            ->whereNotNull('type_operation_id')
            ->distinct()
            ->pluck('type_operation_id');

        $senders = TypeOperation::whereIn('id', $typeOperationIds)
            ->whereNotNull('email_from')
            ->where('email_from', '!=', '')
            ->get()
            ->map(fn (TypeOperation $to) => [
                'email' => $to->email_from,
                'name' => $to->email_from_name,
                'label' => $to->nom.' ('.$to->email_from.')',
            ])
            ->unique('email');

        // Repli sur l'adresse de l'association si aucun type d'opération n'en a une
        if ($senders->isEmpty()) {
            $assoc = CurrentAssociation::tryGet();
            if ($assoc?->email_from) {
                $senders = collect([[
                    'email' => $assoc->email_from,
                    'name' => $assoc->email_from_name,
                    'label' => ($assoc->nom ?? 'Association').' ('.$assoc->email_from.')',
                ]]);
            }
        }

        return $senders;
    }

    private function resolveFirstTypeOperationId(): ?int
    {
        $operationIds = TransactionLigne::whereIn(
            'transaction_id',
            $this->facture->transactions()->pluck('transactions.id')
        )
            ->whereNotNull('operation_id')
            ->distinct()
            ->pluck('operation_id');

        return Operation::whereIn('id', $operationIds)
            ->whereNotNull('type_operation_id')
            ->value('type_operation_id');
    }
}
