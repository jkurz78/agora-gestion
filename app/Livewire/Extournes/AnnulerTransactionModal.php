<?php

declare(strict_types=1);

namespace App\Livewire\Extournes;

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Models\Transaction;
use App\Services\TransactionExtourneService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

final class AnnulerTransactionModal extends Component
{
    public ?int $transactionId = null;

    public bool $isOpen = false;

    public string $libelle = '';

    public string $date = '';

    public string $modePaiement = '';

    public ?string $notes = null;

    /**
     * Cas d'usage :
     *   $wire.dispatch('extourne:open', { id: 42 })
     *   ou directement Livewire::test(...)->call('open', 42)
     */
    #[On('extourne:open')]
    public function open(int $id): void
    {
        $tx = Transaction::find($id);
        if ($tx === null) {
            $this->dispatch('extourne:error', message: 'Transaction introuvable.');

            return;
        }

        if (! $tx->isExtournable()) {
            $this->dispatch('extourne:error', message: 'Cette transaction ne peut pas être annulée.');

            return;
        }

        $this->transactionId = $id;
        $this->libelle = 'Annulation - '.$tx->libelle;
        $this->date = now()->toDateString();
        $this->modePaiement = $tx->mode_paiement->value;
        $this->notes = null;
        $this->isOpen = true;
    }

    public function submit(TransactionExtourneService $service): void
    {
        if ($this->transactionId === null) {
            return;
        }

        $this->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'modePaiement' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $tx = Transaction::find($this->transactionId);
        if ($tx === null) {
            $this->dispatch('extourne:error', message: 'Transaction introuvable.');
            $this->reset(['isOpen', 'transactionId']);

            return;
        }

        try {
            $service->extourner($tx, ExtournePayload::fromOrigine($tx, [
                'libelle' => $this->libelle,
                'date' => $this->date,
                'mode_paiement' => ModePaiement::from($this->modePaiement),
                'notes' => $this->notes,
            ]));
        } catch (AuthorizationException|RuntimeException $e) {
            $this->dispatch('extourne:error', message: $e->getMessage());

            return;
        }

        $this->dispatch('extourne:success');
        $this->close();
    }

    public function close(): void
    {
        $this->reset(['isOpen', 'transactionId', 'libelle', 'date', 'modePaiement', 'notes']);
    }

    public function render(): View
    {
        return view('livewire.extournes.annuler-transaction-modal', [
            'modePaiementCases' => ModePaiement::cases(),
        ]);
    }
}
