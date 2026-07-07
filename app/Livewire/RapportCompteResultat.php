<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportCompteResultat extends Component
{
    #[Url(as: 'n1')]
    public bool $compareN1 = true;

    #[Url(as: 'budget')]
    public bool $compareBudget = true;

    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('rapports.export', [
            'rapport' => 'compte-resultat',
            'format' => $format,
            'exercice' => $exercice,
            'n1' => $this->compareN1 ? '1' : '0',
            'budget' => $this->compareBudget ? '1' : '0',
        ]);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $rapportService = app(RapportService::class);
        $data = $rapportService->compteDeResultat($exercice);

        $labelN = $exercice.'–'.($exercice + 1);
        $labelN1 = ($exercice - 1).'–'.$exercice;

        $totalChargesN = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');
        $totalChargesN1 = collect($data['charges'])->sum('montant_n1');
        $totalProduitsN1 = collect($data['produits'])->sum('montant_n1');
        $resultatCourant = $totalProduitsN - $totalChargesN;
        $resultatCourantN1 = $totalProduitsN1 - $totalChargesN1;

        $provisionsBlock = $rapportService->compteDeResultatProvisions($exercice, $resultatCourant, $resultatCourantN1);

        return view('livewire.rapport-compte-resultat', array_merge([
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'labelN' => $labelN,
            'labelN1' => $labelN1,
            'compareN1' => $this->compareN1,
            'compareBudget' => $this->compareBudget,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatCourant' => $resultatCourant,
        ], $provisionsBlock));
    }
}
