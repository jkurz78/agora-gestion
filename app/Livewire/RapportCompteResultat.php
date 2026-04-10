<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\ProvisionService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportCompteResultat extends Component
{
    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('compta.rapports.export', [
            'rapport' => 'compte-resultat',
            'format' => $format,
            'exercice' => $exercice,
        ]);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->compteDeResultat($exercice);
        $provisionService = app(ProvisionService::class);

        $labelN = $exercice.'–'.($exercice + 1);
        $labelN1 = ($exercice - 1).'–'.$exercice;

        $totalChargesN = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');
        $totalChargesN1 = collect($data['charges'])->sum('montant_n1');
        $totalProduitsN1 = collect($data['produits'])->sum('montant_n1');
        $resultatCourant = $totalProduitsN - $totalChargesN;
        $resultatCourantN1 = $totalProduitsN1 - $totalChargesN1;

        $provisions = $provisionService->provisionsExercice($exercice);
        $provisionsN1 = $provisionService->provisionsExercice($exercice - 1);
        $extournes = $provisionService->extournesExercice($exercice);
        $extournesN1 = $provisionService->extournesExercice($exercice - 1);
        $totalProvisions = $provisionService->totalProvisions($exercice);
        $totalProvisionsN1 = $provisionService->totalProvisions($exercice - 1);
        $totalExtournes = $provisionService->totalExtournes($exercice);
        $totalExtournesN1 = $provisionService->totalExtournes($exercice - 1);

        $resultatBrut = $resultatCourant + $totalExtournes;
        $resultatBrutN1 = $resultatCourantN1 + $totalExtournesN1;
        $resultatNet = $resultatBrut + $totalProvisions;
        $resultatNetN1 = $resultatBrutN1 + $totalProvisionsN1;

        return view('livewire.rapport-compte-resultat', [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'labelN' => $labelN,
            'labelN1' => $labelN1,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatCourant' => $resultatCourant,
            'provisions' => $provisions,
            'provisionsN1' => $provisionsN1,
            'extournes' => $extournes,
            'extournesN1' => $extournesN1,
            'totalProvisions' => $totalProvisions,
            'totalProvisionsN1' => $totalProvisionsN1,
            'totalExtournes' => $totalExtournes,
            'totalExtournesN1' => $totalExtournesN1,
            'resultatBrut' => $resultatBrut,
            'resultatBrutN1' => $resultatBrutN1,
            'resultatNet' => $resultatNet,
            'resultatNetN1' => $resultatNetN1,
        ]);
    }
}
