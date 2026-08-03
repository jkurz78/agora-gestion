<?php

declare(strict_types=1);

namespace App\Livewire\Extournes;

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\Transaction;
use App\Services\TransactionExtourneService;
use App\Services\TransactionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * Modale unifiée "Annuler une transaction".
 *
 * Deux chemins selon le statut :
 *   - Dû / En main → soft-delete avec motif (TransactionService::annuler)
 *   - Remis / Pointé → extourne comptable D↔C (TransactionExtourneService::extourner)
 */
final class AnnulerTransactionModal extends Component
{
    public ?int $transactionId = null;

    public bool $isOpen = false;

    /** 'suppression' | 'extourne' — déterminé à l'ouverture selon le statut */
    public string $mode = 'extourne';

    public string $libelle = '';

    public string $date = '';

    public string $modePaiement = '';

    public ?string $notes = null;

    public string $motif = '';

    public ?string $errorMessage = null;

    /** Libellé humain du statut pour affichage dans la modale */
    public string $statutLabel = '';

    /** Montant formaté pour affichage */
    public string $montantFormate = '';

    #[On('extourne:open')]
    public function open(int $id): void
    {
        $this->errorMessage = null;

        $tx = Transaction::find($id);
        if ($tx === null) {
            $this->errorMessage = 'Transaction introuvable.';

            return;
        }

        if (! $tx->isExtournable()) {
            $this->errorMessage = 'Cette transaction ne peut pas être annulée.';

            return;
        }

        $this->transactionId = $id;
        $this->montantFormate = number_format(abs((float) $tx->montant_total), 2, ',', ' ').' €';
        $this->statutLabel = $tx->statut_reglement?->label() ?? '';

        // Choix du chemin selon le statut
        $this->mode = match ($tx->statut_reglement) {
            StatutReglement::EnAttente, StatutReglement::EnMain => 'suppression',
            default => 'extourne',
        };

        if ($this->mode === 'extourne') {
            $this->libelle = 'Annulation - '.$tx->libelle;
            $this->date = now()->toDateString();
            $this->modePaiement = $tx->mode_paiement?->value ?? '';
            $this->notes = null;
        } else {
            $this->motif = '';
        }

        $this->isOpen = true;
    }

    public function submit(): void
    {
        if ($this->transactionId === null) {
            return;
        }

        if ($this->mode === 'suppression') {
            $this->submitSuppression();
        } else {
            $this->submitExtourne();
        }
    }

    private function submitSuppression(): void
    {
        $this->validate([
            'motif' => ['required', 'string', 'max:500'],
        ], [
            'motif.required' => 'Le motif est obligatoire.',
        ]);

        $this->errorMessage = null;

        $tx = Transaction::find($this->transactionId);
        if ($tx === null) {
            $this->errorMessage = 'Transaction introuvable.';
            $this->reset(['isOpen', 'transactionId']);

            return;
        }

        try {
            app(TransactionService::class)->annuler($tx, $this->motif);
        } catch (AuthorizationException|RuntimeException|\DomainException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->dispatch('extourne:success');
        $this->close();
    }

    private function submitExtourne(): void
    {
        $this->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'modePaiement' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->errorMessage = null;

        $tx = Transaction::find($this->transactionId);
        if ($tx === null) {
            $this->errorMessage = 'Transaction introuvable.';
            $this->reset(['isOpen', 'transactionId']);

            return;
        }

        try {
            app(TransactionExtourneService::class)->extourner($tx, ExtournePayload::fromOrigine($tx, [
                'libelle' => $this->libelle,
                'date' => $this->date,
                'mode_paiement' => ModePaiement::from($this->modePaiement),
                'notes' => $this->notes,
            ]));
        } catch (AuthorizationException|RuntimeException|\DomainException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->dispatch('extourne:success');
        $this->close();
    }

    public function close(): void
    {
        $this->reset([
            'isOpen', 'transactionId', 'mode', 'libelle', 'date',
            'modePaiement', 'notes', 'motif', 'errorMessage',
            'statutLabel', 'montantFormate',
        ]);
    }

    public function render(): View
    {
        return view('livewire.extournes.annuler-transaction-modal', [
            'modePaiementCases' => ModePaiement::cases(),
        ]);
    }
}
