<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Http\Controllers\RapportExportController;
use App\Livewire\BudgetTable;
use App\Models\BudgetLine;
use App\Services\BudgetService;
use App\Services\Compta\PlanComptableSelecteur;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Les six requêtes de l'écran Budget, en un seul endroit.
 *
 * Avant cette extraction, les mêmes six requêtes existaient en TROIS
 * exemplaires : {@see BudgetTable::render()}, le contrôleur
 * d'export PDF ({@see RapportExportController::pdfBudgetData()}),
 * et le helper de test `budgetPdfViewData()`
 * (tests/Feature/Rapports/BudgetPdfImprimableTest.php). Aucune divergence de
 * comportement n'est introduite ici : ce sont les mêmes expressions,
 * simplement déplacées — enveloppes et ventilations restent lues séparément,
 * sans quoi le keyBy() écraserait l'enveloppe par sa ventilation et
 * doublerait tout total (voir le commentaire historique sur
 * BudgetTable::render()).
 */
final class BudgetEcranBuilder
{
    public function __construct(
        private readonly BudgetService $budgetService,
    ) {}

    /**
     * @return array{
     *     depenseGroupes: Collection<string, array{famille: mixed, comptes: Collection}>,
     *     recetteGroupes: Collection<string, array{famille: mixed, comptes: Collection}>,
     *     budgetLines: EloquentCollection<int, BudgetLine>,
     *     ventilations: Collection<int, EloquentCollection<int, BudgetLine>>,
     *     realiseData: array<int, float>,
     *     realiseParOperation: array<int, array<int, float>>,
     * }
     */
    public function pourExercice(int $exercice): array
    {
        return [
            'depenseGroupes' => PlanComptableSelecteur::groupesPourType('depense'),
            'recetteGroupes' => PlanComptableSelecteur::groupesPourType('recette'),
            // Enveloppes et ventilations sont lues SÉPARÉMENT : les mêler dans
            // une seule collection ferait écraser l'enveloppe par sa
            // ventilation au keyBy, et doubler tout total.
            'budgetLines' => BudgetLine::forExercice($exercice)->enveloppes()->get()->keyBy('compte_id'),
            'ventilations' => BudgetLine::forExercice($exercice)
                ->ventilations()
                ->with('operation')
                ->get()
                ->groupBy('compte_id'),
            // Deux requêtes groupées, au lieu d'un appel par compte.
            'realiseData' => $this->budgetService->realiseParCompte($exercice),
            'realiseParOperation' => $this->budgetService->realiseParCompteEtOperation($exercice),
        ];
    }
}
