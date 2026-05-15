<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Enums\HorizonTemporel;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\Participant;
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
            ->with(['operation.typeOperation', 'operation.seances'])
            ->orderByDesc('date_inscription')
            ->get();

        // Group by horizon (pure enum → use ->name as key)
        $byHorizon = $participations->groupBy(
            fn (Participant $p): string => ClassificationTemporelle::pour($p->operation)->name
        );

        return view('livewire.portail.mes-activites', [
            'aVenir' => $byHorizon->get(HorizonTemporel::AVenir->name, collect()),
            'enCours' => $byHorizon->get(HorizonTemporel::EnCours->name, collect()),
            'terminees' => $byHorizon->get(HorizonTemporel::Terminee->name, collect()),
        ])->layout('portail.layouts.authenticated');
    }
}
