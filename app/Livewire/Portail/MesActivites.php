<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Enums\HorizonTemporel;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\Participant;
use App\Models\TypeOperation;
use App\Services\Portail\ClassificationTemporelle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class MesActivites extends Component
{
    use WithPortailTenant;

    public Association $association;

    public ?int $typeOperationId = null;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function render(): View
    {
        $tiers = Auth::guard('tiers-portail')->user();

        $participations = Participant::query()
            ->where('tiers_id', (int) $tiers->id)
            ->with(['operation.typeOperation', 'operation.seances', 'presences'])
            ->orderByDesc('date_inscription')
            ->get();

        // Distinct TypeOperation instances having ≥ 1 participation, sorted alphabetically
        /** @var Collection<int, TypeOperation> $typesActifs */
        $typesActifs = $participations
            ->map(fn (Participant $p): ?TypeOperation => $p->operation?->typeOperation)
            ->filter()
            ->unique('id')
            ->sortBy('nom')
            ->values();

        // Resolve which type to display (local variable only — never mutate $this->typeOperationId here)
        if ($typesActifs->isEmpty()) {
            $typeSelectionne = null;
        } elseif ($this->typeOperationId !== null) {
            $typeSelectionne = $typesActifs->firstWhere('id', $this->typeOperationId)
                ?? $typesActifs->first();
        } else {
            // Default: first alphabetical (covers single-type case too)
            $typeSelectionne = $typesActifs->first();
        }

        // Filter participations to the selected type
        $filtered = $typeSelectionne !== null
            ? $participations->filter(
                fn (Participant $p): bool => (int) ($p->operation?->type_operation_id) === (int) $typeSelectionne->id
            )
            : $participations;

        // Group filtered participations by temporal horizon
        $byHorizon = $filtered->groupBy(
            fn (Participant $p): string => ClassificationTemporelle::pour($p->operation)->name
        );

        return view('livewire.portail.mes-activites', [
            'typesActifs' => $typesActifs,
            'typeSelectionne' => $typeSelectionne,
            'aVenir' => $byHorizon->get(HorizonTemporel::AVenir->name, collect()),
            'enCours' => $byHorizon->get(HorizonTemporel::EnCours->name, collect()),
            'terminees' => $byHorizon->get(HorizonTemporel::Terminee->name, collect()),
        ])->layout('portail.layouts.authenticated');
    }
}
