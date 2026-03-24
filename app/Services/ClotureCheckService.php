<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutRapprochement;
use App\Models\BudgetLine;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\VirementInterne;

final class ClotureCheckService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly SoldeService $soldeService,
    ) {}

    public function executer(int $annee): ClotureCheckResult
    {
        $range = $this->exerciceService->dateRange($annee);
        $start = $range['start']->toDateString();
        $end = $range['end']->toDateString();

        return new ClotureCheckResult(
            bloquants: [
                $this->checkRapprochementsEnCours($start, $end),
                $this->checkLignesSansSousCategorie($annee),
                $this->checkVirementsDesequilibres($start, $end),
            ],
            avertissements: [
                $this->checkTransactionsNonPointees($start, $end),
                $this->checkBudgetAbsent($annee),
            ],
            soldesComptes: $this->calculerSoldesComptes(),
        );
    }

    private function checkRapprochementsEnCours(string $start, string $end): CheckItem
    {
        $count = RapprochementBancaire::where('statut', StatutRapprochement::EnCours)
            ->whereBetween('date_fin', [$start, $end])
            ->count();

        return new CheckItem(
            nom: 'Rapprochements en cours',
            ok: $count === 0,
            message: $count === 0
                ? 'Aucun rapprochement en cours'
                : "{$count} rapprochement(s) en cours sur la période",
        );
    }

    private function checkLignesSansSousCategorie(int $annee): CheckItem
    {
        $count = TransactionLigne::where('exercice', $annee)
            ->whereNull('sous_categorie_id')
            ->count();

        return new CheckItem(
            nom: 'Lignes sans sous-catégorie',
            ok: $count === 0,
            message: $count === 0
                ? 'Toutes les lignes ont une sous-catégorie'
                : "{$count} ligne(s) sans sous-catégorie",
        );
    }

    private function checkVirementsDesequilibres(string $start, string $end): CheckItem
    {
        $count = VirementInterne::whereBetween('date', [$start, $end])
            ->where(function ($q) {
                $q->whereNull('compte_source_id')
                    ->orWhereNull('compte_destination_id');
            })
            ->count();

        return new CheckItem(
            nom: 'Virements déséquilibrés',
            ok: $count === 0,
            message: $count === 0
                ? 'Tous les virements sont équilibrés'
                : "{$count} virement(s) déséquilibré(s)",
        );
    }

    private function checkTransactionsNonPointees(string $start, string $end): CheckItem
    {
        $count = Transaction::whereNull('rapprochement_id')
            ->whereBetween('date', [$start, $end])
            ->count();

        return new CheckItem(
            nom: 'Transactions non pointées',
            ok: $count === 0,
            message: $count === 0
                ? 'Toutes les transactions sont pointées'
                : "{$count} transaction(s) non pointée(s)",
        );
    }

    private function checkBudgetAbsent(int $annee): CheckItem
    {
        $count = BudgetLine::forExercice($annee)->count();

        return new CheckItem(
            nom: 'Budget absent',
            ok: $count > 0,
            message: $count > 0
                ? "{$count} ligne(s) de budget"
                : 'Aucune ligne de budget pour cet exercice',
        );
    }

    /**
     * @return array<string, float>
     */
    private function calculerSoldesComptes(): array
    {
        $soldes = [];
        foreach (CompteBancaire::orderBy('nom')->get() as $compte) {
            $soldes[$compte->nom] = $this->soldeService->solde($compte);
        }

        return $soldes;
    }
}
