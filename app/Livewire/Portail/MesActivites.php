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

    public TypeOperation $typeOperation;

    public function mount(Association $association, TypeOperation $typeOperation): void
    {
        $this->association = $association;
        $this->typeOperation = $typeOperation;

        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        $hasParticipation = Participant::query()
            ->where('tiers_id', (int) $tiers->id)
            ->whereHas('operation', fn ($q) => $q->where('type_operation_id', (int) $this->typeOperation->id))
            ->exists();

        abort_unless($hasParticipation, 403);
    }

    public function render(): View
    {
        $tiers = Auth::guard('tiers-portail')->user();

        $participations = Participant::query()
            ->where('tiers_id', (int) $tiers->id)
            ->whereHas('operation', fn ($q) => $q->where('type_operation_id', (int) $this->typeOperation->id))
            ->with(['operation.typeOperation', 'operation.seances', 'presences', 'formulaireToken'])
            ->orderByDesc('date_inscription')
            ->get();

        $byHorizon = $participations->groupBy(
            fn (Participant $p): string => ClassificationTemporelle::pour($p->operation)->name
        );

        return view('livewire.portail.mes-activites', [
            'titre' => 'Mes '.mb_strtolower($this->typeOperation->nom),
            'aVenir' => $byHorizon->get(HorizonTemporel::AVenir->name, collect()),
            'enCours' => $byHorizon->get(HorizonTemporel::EnCours->name, collect()),
            'terminees' => $byHorizon->get(HorizonTemporel::Terminee->name, collect()),
            'portailAssociation' => $this->association,
        ])->layout('portail.layouts.authenticated');
    }
}
