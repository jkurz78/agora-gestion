<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Services\Tiers\TiersDonsTimelineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class MesDons extends Component
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
        $dto = app(TiersDonsTimelineService::class)->forTiers($tiers);

        return view('livewire.portail.mes-dons', [
            'donsTimeline' => $dto,
            'urlNouveauDon' => $this->association->urlNouveauDon(),
            'portailAssociation' => $this->association,
        ])->layout('portail.layouts.authenticated');
    }
}
