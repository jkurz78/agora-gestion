<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Services\Tiers\TiersCommunicationsTimelineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class MesMessages extends Component
{
    use WithPagination;
    use WithPortailTenant;

    public Association $association;

    public ?int $messageOuvertId = null;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function toggleMessage(int $id): void
    {
        $tiers = Auth::guard('tiers-portail')->user();
        if ($tiers === null) {
            return;
        }

        $page = (int) $this->getPage();
        $timeline = app(TiersCommunicationsTimelineService::class)
            ->forTiers($tiers, null, $page, 25);

        /** @var array<int> $idsPageCourante */
        $idsPageCourante = array_map('intval', collect($timeline->emails->items())->pluck('id')->all());

        if (! in_array($id, $idsPageCourante, true)) {
            // Intrusion : ID hors page courante — ignoré silencieusement
            return;
        }

        $this->messageOuvertId = ($this->messageOuvertId === $id) ? null : $id;
    }

    public function updatingPage(): void
    {
        $this->messageOuvertId = null;
    }

    public function render(): View
    {
        $tiers = Auth::guard('tiers-portail')->user();
        $page = (int) $this->getPage();
        $timeline = app(TiersCommunicationsTimelineService::class)
            ->forTiers($tiers, null, $page, 25);

        return view('livewire.portail.mes-messages', [
            'timeline' => $timeline,
            'portailAssociation' => $this->association,
        ])->layout('portail.layouts.authenticated');
    }
}
