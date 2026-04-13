<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\StatutReglement;
use App\Models\RemiseBancaire;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class RemiseBancaireShow extends Component
{
    public RemiseBancaire $remise;

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()?->role?->canWrite(Espace::Gestion) ?? false;
    }

    public function estBrouillon(): bool
    {
        return ! $this->remise->transactions()
            ->whereIn('statut_reglement', [
                StatutReglement::Recu->value,
                StatutReglement::Pointe->value,
            ])
            ->exists();
    }

    public function supprimer(): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(RemiseBancaireService::class)->supprimer($this->remise);
            session()->flash('success', 'Remise supprimée.');
            $this->redirect(route('banques.remises.index'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $verrouille = $this->remise->isVerrouillee();
        $isBrouillon = $this->estBrouillon();

        $transactions = $this->remise->transactions()
            ->with(['tiers', 'lignes.operation'])
            ->orderBy('reference')
            ->get();

        $totalMontant = $transactions->sum('montant_total');

        return view('livewire.remise-bancaire-show', [
            'transactions' => $transactions,
            'totalMontant' => $totalMontant,
            'verrouille' => $verrouille,
            'isBrouillon' => $isBrouillon,
        ]);
    }
}
