<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\Rapports\CompteResultatBuilder;
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
        // Total budget de la ligne TOTAL DEPENSES/RECETTES : somme des budgets
        // des familles de $data['charges']/$data['produits'], EXACTEMENT la
        // même collection que celle parcourue par la vue pour afficher les
        // lignes de détail — jamais une requête séparée sur budget_lines.
        // $data['charges']/$data['produits'] a trois sources de lignes
        // (CompteResultatBuilder::buildHierarchyFull()) : les écritures de N,
        // celles de N-1, et les enveloppes budgétaires elles-mêmes — un compte
        // budgété sans aucun mouvement y figure donc désormais, avec sa propre
        // ligne. Ce que la règle ci-dessus ne change pas : le total ne peut
        // toujours pas diverger de la colonne qu'il somme, puisque c'est la
        // même collection des deux côtés. Une réserve, et une seule : la vue
        // masque les lignes entièrement à zéro ($scVisibles), que ce total
        // compte quand même — une enveloppe posée à 0 € sur un compte non
        // mouvementé affiche donc « 0,00 € » en total, face à un détail vide.
        $totalChargesBudget = CompteResultatBuilder::sommeBudgetSection($data['charges']);
        $totalProduitsBudget = CompteResultatBuilder::sommeBudgetSection($data['produits']);
        $resultatCourant = $totalProduitsN - $totalChargesN;
        $resultatCourantN1 = $totalProduitsN1 - $totalChargesN1;
        // Budget du résultat = budget des produits - budget des charges. null
        // seulement si AUCUNE des deux sections n'a de budget ; si une seule en
        // a un, l'autre compte pour zéro — sinon un budget posé sur les seules
        // dépenses ne produirait jamais de résultat prévu.
        $resultatBudget = ($totalChargesBudget === null && $totalProduitsBudget === null)
            ? null
            : ($totalProduitsBudget ?? 0.0) - ($totalChargesBudget ?? 0.0);

        return view('livewire.rapport-compte-resultat', [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'labelN' => $labelN,
            'labelN1' => $labelN1,
            'compareN1' => $this->compareN1,
            'compareBudget' => $this->compareBudget,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'totalChargesN1' => $totalChargesN1,
            'totalProduitsN1' => $totalProduitsN1,
            'totalChargesBudget' => $totalChargesBudget,
            'totalProduitsBudget' => $totalProduitsBudget,
            'resultatCourant' => $resultatCourant,
            'resultatCourantN1' => $resultatCourantN1,
            'resultatBudget' => $resultatBudget,
        ]);
    }
}
