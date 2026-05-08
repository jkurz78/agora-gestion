<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Models\Tiers;
use App\Services\Tiers\TiersAdhesionTimelineService;
use Illuminate\View\View;
use Livewire\Component;

final class Adhesion extends Component
{
    public Tiers $tiers;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function render(): View
    {
        $dto = app(TiersAdhesionTimelineService::class)->forTiers($this->tiers);

        return view('livewire.tiers.onglets.adhesion', compact('dto'));
    }
}
