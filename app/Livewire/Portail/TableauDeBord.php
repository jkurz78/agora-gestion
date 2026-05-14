<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\PortailSectionsResolver;
use App\Support\Portail\PortailSectionDTO;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class TableauDeBord extends Component
{
    use WithPortailTenant;

    public Association $association;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function render(): View
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();

        $sections = app(PortailSectionsResolver::class)
            ->resolve($tiers)
            ->filter(fn (PortailSectionDTO $s): bool => $s->id !== 'tableau-de-bord')
            ->values();

        return view('livewire.portail.tableau-de-bord', [
            'tiers' => $tiers,
            'sections' => $sections,
        ])->layout('portail.layouts.authenticated');
    }
}
