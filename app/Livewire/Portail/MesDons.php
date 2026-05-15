<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Exceptions\RecuFiscalException;
use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Services\RecuFiscalService;
use App\Services\Tiers\TiersDonsTimelineService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        ])->layout('portail.layouts.authenticated');
    }

    public function telechargerRecuFiscal(int $ligneId): mixed
    {
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        // Ownership : la ligne doit apparaître dans le résultat du service pour CE Tiers.
        // Le service fait foi (mêmes règles que l'affichage = recette + sous-cat usage Don + transaction.tiers_id).
        $dto = app(TiersDonsTimelineService::class)->forTiers($tiers);
        $ligneTrouvee = null;
        foreach ($dto->annees as $annee) {
            foreach ($annee->lignes as $donDto) {
                if ((int) $donDto->ligne->id === (int) $ligneId) {
                    $ligneTrouvee = $donDto->ligne;
                    break 2;
                }
            }
        }
        abort_unless($ligneTrouvee !== null, 403);

        try {
            $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligneTrouvee);
        } catch (RecuFiscalException $e) {
            session()->flash('portail.error', $e->getMessage());

            return null;
        }

        Log::info('portail.recu.fiscal.telecharge', [
            'ligne_id' => $ligneTrouvee->id,
            'tiers_id' => $tiers->id,
        ]);

        return app(RecuFiscalService::class)->streamDownloadResponse($recu);
    }
}
