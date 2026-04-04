<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class RemiseBancaireSelection extends Component
{
    public RemiseBancaire $remise;

    /** @var list<int> */
    public array $selectedIds = [];

    public string $filterOperation = '';

    public string $filterTiers = '';

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;

        // Pre-select already-linked reglements (for modification flow)
        $this->selectedIds = Reglement::where('remise_id', $remise->id)
            ->pluck('id')
            ->toArray();
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function toggleReglement(int $id): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (in_array($id, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$id]));
        } else {
            $this->selectedIds[] = $id;
        }
    }

    public function valider(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (count($this->selectedIds) === 0) {
            $this->addError('selection', 'Sélectionnez au moins un règlement.');

            return;
        }

        session(['remise_selected_ids' => $this->selectedIds]);
        $this->redirect(route('gestion.remises-bancaires.validation', $this->remise));
    }

    public function render(): View
    {
        $query = Reglement::with(['participant.tiers', 'seance.operation'])
            ->where('mode_paiement', $this->remise->mode_paiement->value)
            ->where('montant_prevu', '>', 0)
            ->where(function ($q) {
                $q->whereNull('remise_id')
                    ->orWhere('remise_id', $this->remise->id);
            });

        if ($this->filterOperation !== '') {
            $query->whereHas('seance.operation', fn ($q) => $q->where('id', $this->filterOperation));
        }

        if ($this->filterTiers !== '') {
            $query->whereHas('participant.tiers', fn ($q) => $q
                ->where('nom', 'like', "%{$this->filterTiers}%")
                ->orWhere('prenom', 'like', "%{$this->filterTiers}%")
            );
        }

        $reglements = $query->get()->sortBy(fn ($r) => [
            $r->seance->operation->nom ?? '',
            $r->seance->numero ?? 0,
            $r->participant->tiers->nom ?? '',
        ])->values();

        $totalSelected = $reglements->whereIn('id', $this->selectedIds)->sum('montant_prevu');
        $countSelected = count($this->selectedIds);

        $operations = $reglements->map(fn ($r) => $r->seance->operation)->unique('id')->sortBy('nom')->values();

        return view('livewire.remise-bancaire-selection', [
            'reglements' => $reglements,
            'totalSelected' => $totalSelected,
            'countSelected' => $countSelected,
            'operations' => $operations,
        ]);
    }
}
