<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
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
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function supprimer(): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            app(RemiseBancaireService::class)->supprimer($this->remise);
            session()->flash('success', 'Remise supprimée.');
            $this->redirect(route('compta.banques.remises.index'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $verrouille = $this->remise->isVerrouillee();
        $isBrouillon = $this->remise->virement_id === null;

        if ($isBrouillon) {
            // Brouillon : afficher les règlements persistés
            $reglements = Reglement::with(['participant.tiers', 'seance.operation'])
                ->where('remise_id', $this->remise->id)
                ->get()
                ->sortBy(fn ($r) => [
                    $r->seance->operation->nom ?? '',
                    $r->seance->numero ?? 0,
                    $r->participant->tiers->nom ?? '',
                ])->values();

            $totalMontant = $reglements->sum('montant_prevu');

            return view('livewire.remise-bancaire-show', [
                'transactions' => collect(),
                'reglements' => $reglements,
                'totalMontant' => $totalMontant,
                'verrouille' => $verrouille,
                'isBrouillon' => true,
            ]);
        }

        $transactions = Transaction::where('remise_id', $this->remise->id)
            ->with(['tiers', 'lignes.operation'])
            ->orderBy('reference')
            ->get();

        $totalMontant = $transactions->sum('montant_total');

        return view('livewire.remise-bancaire-show', [
            'transactions' => $transactions,
            'reglements' => collect(),
            'totalMontant' => $totalMontant,
            'verrouille' => $verrouille,
            'isBrouillon' => false,
        ]);
    }
}
