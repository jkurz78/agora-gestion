<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class RemiseBancaireShow extends Component
{
    public RemiseBancaire $remise;

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;
    }

    public function supprimer(): void
    {
        try {
            app(RemiseBancaireService::class)->supprimer($this->remise);
            session()->flash('success', 'Remise supprimée.');
            $this->redirect(route('gestion.remises-bancaires'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $transactions = Transaction::where('remise_id', $this->remise->id)
            ->with(['tiers', 'lignes.operation'])
            ->orderBy('reference')
            ->get();

        $totalMontant = $transactions->sum('montant_total');
        $verrouille = $this->remise->isVerrouillee();

        return view('livewire.remise-bancaire-show', [
            'transactions' => $transactions,
            'totalMontant' => $totalMontant,
            'verrouille' => $verrouille,
        ]);
    }
}
