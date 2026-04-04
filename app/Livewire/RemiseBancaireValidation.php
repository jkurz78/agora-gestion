<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class RemiseBancaireValidation extends Component
{
    public RemiseBancaire $remise;

    /** @var list<int> */
    public array $selectedIds = [];

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;
        $this->selectedIds = session('remise_selected_ids', []);
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
                $service->modifier($this->remise, $this->selectedIds);
            } else {
                $service->comptabiliser($this->remise, $this->selectedIds);
            }

            session()->forget('remise_selected_ids');
            session()->flash('success', 'Remise comptabilisée avec succès.');
            $this->redirect(route('gestion.remises-bancaires'));
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

        $totalMontant = $reglements->sum('montant_prevu');

        return view('livewire.remise-bancaire-validation', [
            'reglements' => $reglements,
            'totalMontant' => $totalMontant,
        ]);
    }
}
