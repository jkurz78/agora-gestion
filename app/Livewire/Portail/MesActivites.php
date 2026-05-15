<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Enums\HorizonTemporel;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\Participant;
use App\Models\TypeOperation;
use App\Services\Portail\ClassificationTemporelle;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class MesActivites extends Component
{
    use WithPortailTenant;

    public Association $association;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function render(): View
    {
        $tiers = Auth::guard('tiers-portail')->user();

        $participations = Participant::query()
            ->where('tiers_id', (int) $tiers->id)
            ->with(['operation.typeOperation', 'operation.seances', 'presences', 'formulaireToken'])
            ->orderByDesc('date_inscription')
            ->get();

        // Distinct TypeOperation instances having ≥ 1 participation, sorted alphabetically
        $typesActifs = $participations
            ->map(fn (Participant $p): ?TypeOperation => $p->operation?->typeOperation)
            ->filter()
            ->unique('id')
            ->sortBy('nom')
            ->values();

        // For each type, group participations by temporal horizon
        $blocs = $typesActifs->map(function (TypeOperation $type) use ($participations): array {
            $partsType = $participations->filter(
                fn (Participant $p): bool => (int) ($p->operation?->type_operation_id) === (int) $type->id
            );
            $byHorizon = $partsType->groupBy(
                fn (Participant $p): string => ClassificationTemporelle::pour($p->operation)->name
            );

            return [
                'type' => $type,
                'aVenir' => $byHorizon->get(HorizonTemporel::AVenir->name, collect()),
                'enCours' => $byHorizon->get(HorizonTemporel::EnCours->name, collect()),
                'terminees' => $byHorizon->get(HorizonTemporel::Terminee->name, collect()),
            ];
        });

        return view('livewire.portail.mes-activites', [
            'blocs' => $blocs,
            'portailAssociation' => $this->association,
        ])->layout('portail.layouts.authenticated');
    }
}
