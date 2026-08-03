<?php

declare(strict_types=1);

namespace App\Livewire\Compta;

use App\Models\Transaction;
use App\Services\Compta\PosteTiersReglementService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

final class AnnulationReglementTiersModal extends Component
{
    public ?int $transactionReglementId = null;

    public string $dateReglement = '';

    public string $montantReglement = '';

    #[On('poste-tiers-reglement:annuler')]
    public function ouvrir(int $transactionReglementId): void
    {
        $transaction = Transaction::query()->findOrFail($transactionReglementId);

        $this->resetValidation();
        $this->transactionReglementId = (int) $transaction->id;
        $this->dateReglement = $transaction->date->toDateString();
        $this->montantReglement = number_format((float) $transaction->montant_total, 2, ',', ' ').' €';

        $this->dispatch('poste-tiers-reglement-annulation-modal-open');
    }

    public function annuler(): void
    {
        if ($this->transactionReglementId === null) {
            $this->addError('annulation', 'Aucun règlement n’est sélectionné.');

            return;
        }

        try {
            app(PosteTiersReglementService::class)->annuler($this->transactionReglementId);
        } catch (RuntimeException $exception) {
            $this->addError('annulation', $exception->getMessage());

            return;
        }

        $this->dispatch('poste-tiers-reglement:annule');
        $this->fermer();
    }

    public function fermer(): void
    {
        $this->dispatch('poste-tiers-reglement-annulation-modal-close');
        $this->reset(['transactionReglementId', 'dateReglement', 'montantReglement']);
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.compta.annulation-reglement-tiers-modal');
    }
}
