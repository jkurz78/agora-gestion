<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Models\Tiers;
use App\Services\Tiers\TiersDocumentsTimelineService;
use Illuminate\View\View;
use Livewire\Component;

final class Documents extends Component
{
    public Tiers $tiers;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function render(): View
    {
        $timeline = app(TiersDocumentsTimelineService::class)->forTiers($this->tiers);

        return view('livewire.tiers.onglets.documents', [
            'timeline' => $timeline,
        ]);
    }
}
