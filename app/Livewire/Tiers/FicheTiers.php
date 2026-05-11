<?php

declare(strict_types=1);

namespace App\Livewire\Tiers;

use App\Models\Tiers;
use App\Services\Tiers\TiersAdhesionTimelineService;
use App\Services\Tiers\TiersDonsTimelineService;
use App\Services\Tiers\TiersOperationsTimelineService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class FicheTiers extends Component
{
    public Tiers $tiers;

    #[Url(as: 'onglet')]
    public ?string $onglet = null;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function render(): View
    {
        $donsCount = app(TiersDonsTimelineService::class)
            ->forTiers($this->tiers)
            ->totalCount;

        $onglets = [
            ['key' => 'coordonnees', 'label' => 'Coordonnées', 'count' => null],
        ];

        if ($donsCount > 0) {
            $onglets[] = ['key' => 'dons', 'label' => 'Dons', 'count' => $donsCount];
        }

        $adhesionsCount = app(TiersAdhesionTimelineService::class)
            ->forTiers($this->tiers)
            ->totalCount;

        if ($adhesionsCount > 0) {
            $onglets[] = ['key' => 'adhesion', 'label' => 'Adhésion', 'count' => $adhesionsCount];
        }

        $nbParticipations = app(TiersOperationsTimelineService::class)->forTiers($this->tiers)->totalCount;
        if ($nbParticipations > 0) {
            $onglets[] = ['key' => 'operations', 'label' => 'Opérations', 'count' => $nbParticipations];
        }

        $current = in_array($this->onglet, array_column($onglets, 'key'), true)
            ? $this->onglet
            : 'coordonnees';

        return view('livewire.tiers.fiche-tiers', [
            'onglets' => $onglets,
            'currentOnglet' => $current,
        ]);
    }
}
