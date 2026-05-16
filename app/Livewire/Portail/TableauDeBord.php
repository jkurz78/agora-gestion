<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Enums\HorizonTemporel;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\FormulaireToken;
use App\Models\Tiers;
use App\Services\Portail\ClassificationTemporelle;
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

        $tokensActifs = FormulaireToken::query()
            ->whereHas('participant', fn ($q) => $q->where('tiers_id', (int) $tiers->id))
            ->where('expire_at', '>=', today())
            ->whereNull('rempli_at')
            ->with(['participant.operation.typeOperation', 'participant.operation.seances'])
            ->get()
            ->reject(fn (FormulaireToken $t): bool => ClassificationTemporelle::pour($t->participant->operation) === HorizonTemporel::Terminee)
            ->values();

        $alertes = $tokensActifs->take(3);
        $alertesAutres = max(0, $tokensActifs->count() - 3);

        return view('livewire.portail.tableau-de-bord', [
            'tiers' => $tiers,
            'sections' => $sections,
            'alertes' => $alertes,
            'alertesAutres' => $alertesAutres,
        ])->layout('portail.layouts.authenticated');
    }
}
