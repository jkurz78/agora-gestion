<?php

declare(strict_types=1);

namespace App\Livewire\Portail\FacturePartenaire;

use App\Enums\StatutFactureDeposee;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Services\Portail\FacturePartenaireService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Livewire\Component;

final class AtraiterIndex extends Component
{
    use WithPortailTenant;

    public Association $association;

    public function mount(Association $association): void
    {
        $this->association = $association;
    }

    public function oublier(int $depotId): void
    {
        $tiers = Auth::guard('tiers-portail')->user();
        $depot = FacturePartenaireDeposee::findOrFail($depotId);

        app(FacturePartenaireService::class)->oublier($depot, $tiers);

        session()->flash('portail.success', 'Facture supprimée.');
    }

    public function render(): View
    {
        $tiers = Auth::guard('tiers-portail')->user();

        $depots = FacturePartenaireDeposee::where('tiers_id', $tiers->id)
            ->where('statut', StatutFactureDeposee::Soumise)
            ->orderByDesc('date_facture')
            ->get()
            ->map(function (FacturePartenaireDeposee $depot) {
                $depot->pdf_url = URL::signedRoute('portail.factures.pdf', [
                    'association' => $this->association->slug,
                    'depot' => $depot->id,
                ]);

                return $depot;
            });

        return view('livewire.portail.facture-partenaire.atraiter-index', [
            'depots' => $depots,
        ])->layout('portail.layouts.app');
    }
}
