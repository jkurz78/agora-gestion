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

final class RemiseBancaireValidation extends Component
{
    public RemiseBancaire $remise;

    /** @var list<int> */
    public array $selectedIds = [];

    /** @var list<int> */
    public array $selectedTransactionIds = [];

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;

        // Lire depuis la DB (brouillon persisté), fallback session pour rétrocompatibilité
        $dbIds = Reglement::where('remise_id', $remise->id)->pluck('id')->toArray();
        $this->selectedIds = count($dbIds) > 0 ? $dbIds : session('remise_selected_ids', []);

        $this->selectedTransactionIds = $remise->transactionsDirectes()->pluck('id')->all();
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function comptabiliser(): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            $service = app(RemiseBancaireService::class);

            if ($this->remise->virement_id !== null) {
                $service->modifier($this->remise, $this->selectedIds, $this->selectedTransactionIds);
            } else {
                $service->comptabiliser($this->remise, $this->selectedIds, $this->selectedTransactionIds);
            }

            session()->forget('remise_selected_ids');
            session()->flash('success', 'Remise comptabilisée avec succès.');
            $this->redirect(route('compta.banques.remises.index'));
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $reglements = Reglement::with(['participant.tiers', 'seance.operation'])
            ->whereIn('id', $this->selectedIds)
            ->get()
            ->sortBy(fn ($r) => [
                $r->seance->operation->nom ?? '',
                $r->seance->numero ?? 0,
                $r->participant->tiers->nom ?? '',
            ])->values();

        $transactionsDirectes = Transaction::whereIn('id', $this->selectedTransactionIds)
            ->with(['tiers', 'compte'])
            ->get();

        $totalMontant = $reglements->sum('montant_prevu') + $transactionsDirectes->sum('montant_total');

        return view('livewire.remise-bancaire-validation', [
            'reglements' => $reglements,
            'transactionsDirectes' => $transactionsDirectes,
            'totalMontant' => $totalMontant,
            'countTotal' => $reglements->count() + $transactionsDirectes->count(),
        ]);
    }
}
