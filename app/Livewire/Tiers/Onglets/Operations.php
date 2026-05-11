<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Models\Tiers;
use App\Services\Tiers\TiersOperationsTimelineService;
use Illuminate\View\View;
use Livewire\Component;

final class Operations extends Component
{
    public Tiers $tiers;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function render(): View
    {
        $participations = app(TiersOperationsTimelineService::class)->forTiers($this->tiers);

        return view('livewire.tiers.onglets.operations', compact('participations'));
    }
}
