<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Exceptions\RecuFiscalException;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Adhesion;
use App\Models\Association;
use App\Services\RecuFiscalService;
use App\Services\Tiers\DTO\AdhesionLigneDTO;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

final class MesAdhesions extends Component
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

        $adhesions = Adhesion::query()
            ->where('tiers_id', (int) $tiers->id)
            ->with(['formuleAdhesion', 'transaction.lignes.recuFiscalActif'])
            ->orderByDesc('date_fin')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Adhesion $a): AdhesionLigneDTO => new AdhesionLigneDTO($a));

        return view('livewire.portail.mes-adhesions', [
            'adhesions' => $adhesions,
            'portailAssociation' => $this->association,
            'urlRenouvellement' => $this->association->urlRenouvellementAdhesion(),
        ])->layout('portail.layouts.authenticated');
    }

    public function telechargerRecuCotisation(int $adhesionId): mixed
    {
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        $adhesion = Adhesion::find($adhesionId);
        abort_unless($adhesion !== null, 404);
        abort_unless((int) $adhesion->tiers_id === (int) $tiers->id, 403);

        try {
            $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion);
        } catch (RecuFiscalException $e) {
            session()->flash('portail.error', $e->getMessage());

            return null;
        }

        Log::info('portail.recu.cotisation.telecharge', [
            'adhesion_id' => $adhesion->id,
            'tiers_id' => $tiers->id,
        ]);

        return app(RecuFiscalService::class)->streamDownloadResponse($recu);
    }
}
