<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\Rapports\ArbreSelecteurOperations;
use App\Services\RapportService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Budget ventilé par opération — le rapport du menu, et l'onglet de la fiche
 * opération, qui n'est que ce même composant avec `:selectedOperationIds`
 * pré-rempli. Le motif de l'onglet « Compte de résultat ».
 *
 * Pas de toggle séances ni tiers : ils n'ont aucun sens face à un budget, qui
 * ne descend pas sous le compte.
 */
final class RapportBudgetOperations extends Component
{
    /** @var array<int, int> */
    #[Url(as: 'ops')]
    public array $selectedOperationIds = [];

    /**
     * Vrai quand la sélection reçue a été entièrement écartée. Information non
     * bloquante affichée dans la vue — un écran vide sans explication ferait
     * croire à une panne.
     */
    public bool $selectionIgnoree = false;

    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('rapports.export', [
            'rapport' => 'budget-operations',
            'format' => $format,
            'exercice' => $exercice,
            'ops' => $this->selectedOperationIds,
        ]);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $rapportService = app(RapportService::class);

        // avecBudget: true — une opération ventilée mais pas encore dépensée
        // doit être sélectionnable, et surtout survivre à l'intersection
        // ci-dessous dans l'onglet de sa propre fiche : sans ce drapeau, une
        // opération qui n'a qu'un budget serait écartée par SEL-01 et l'onglet
        // s'afficherait vide sur sa propre fiche.
        $eligibleIds = $rapportService->operationsEligibles($exercice, avecBudget: true);

        // SEL-04 : la sélection reçue (URL, ou :selectedOperationIds de
        // l'onglet fiche opération) n'est jamais fiable. On intersecte en
        // mémoire avec $eligibleIds déjà en main, comme
        // RapportCompteResultatOperations::render() — passer par
        // normaliserOperations() referait partir pourExercice() une seconde
        // fois (elle l'appelle déjà en interne pour construire $eligibleIds),
        // donc une seconde UNION sur transaction_lignes +
        // transaction_ligne_affectations + budget_lines à chaque rendu.
        $demandes = collect($this->selectedOperationIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $selection = array_values(array_intersect($demandes, $eligibleIds));

        $this->selectionIgnoree = $demandes !== [] && $selection === [];

        return view('livewire.rapport-budget-operations', [
            'operationTree' => app(ArbreSelecteurOperations::class)->construire($eligibleIds),
            'operations' => $selection === []
                ? []
                : $rapportService->budgetParOperations($exercice, $selection),
            'hasSelection' => $selection !== [],
            'aucuneOperationEligible' => $eligibleIds === [],
            'selectionIgnoree' => $this->selectionIgnoree,
            'exercice' => $exercice,
        ]);
    }
}
