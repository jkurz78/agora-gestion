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
        // même collection des deux côtés.
        $totalChargesBudget = self::sommeBudget($data['charges']);
        $totalProduitsBudget = self::sommeBudget($data['produits']);
        $resultatCourant = $totalProduitsN - $totalChargesN;
        $resultatCourantN1 = $totalProduitsN1 - $totalChargesN1;

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
        ]);
    }

    /**
     * Somme des budgets des familles d'une section, en distinguant « aucun
     * budget nulle part dans la section » (null, comme pour une ligne sans
     * budget individuelle → tiret) de « la section budgète, et ça tombe à
     * 0 € » (0.0, un vrai total). Collection::sum() ne fait pas cette
     * différence : sur une collection vide ou entièrement à null, elle rend
     * 0 — ce qui afficherait un total budget à 0 € (et un écart délirant)
     * pour une section qui n'a en réalité aucune ligne budgétée.
     *
     * @param  array<int, array{budget: ?float}>  $categories
     */
    private static function sommeBudget(array $categories): ?float
    {
        $budgets = collect($categories)->pluck('budget')->filter(fn (?float $b): bool => $b !== null);

        return $budgets->isEmpty() ? null : $budgets->sum();
    }
}
