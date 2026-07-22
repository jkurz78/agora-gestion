<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\JournalComptable;
use App\Enums\OrigineANouveau;
use App\Enums\StatutRapprochement;
use App\Models\ANouveauGeneration;
use App\Models\BudgetLine;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\VirementInterne;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use Throwable;

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
                $this->checkOuverturePrecedente($annee),
                $this->checkRapprochementsEnCours($start, $end),
                $this->checkLignesSansCompte($annee),
                $this->checkVirementsDesequilibres($start, $end),
                $this->checkExerciceCible($annee),
                $this->checkANouveau($annee),
            ],
            avertissements: [
                $this->checkTransactionsNonPointees($start, $end),
                $this->checkBudgetAbsent($annee),
                $this->checkMouvementsExerciceCible($annee),
            ],
            soldesComptes: $this->calculerSoldesComptes($annee),
        );
    }

    public function checkOuverturePrecedente(int $annee): CheckItem
    {
        if (! config('compta.use_partie_double')) {
            return new CheckItem(
                'Soldes d’ouverture',
                true,
                'Contrôle des soldes d’ouverture non requis en mode comptable historique',
            );
        }

        $precedent = Exercice::query()->where('annee', $annee - 1)->first();
        if ($precedent === null) {
            return new CheckItem(
                'Soldes d’ouverture',
                true,
                'Aucun exercice précédent enregistré',
            );
        }

        $generationActive = ANouveauGeneration::activePourCible($annee);
        $disponibles = $generationActive !== null
            && ($precedent->isCloture() || $generationActive->origine === OrigineANouveau::RepriseInitiale);

        return new CheckItem(
            nom: 'Soldes d’ouverture',
            ok: $disponibles,
            message: $disponibles
                ? 'Les soldes d’ouverture sont disponibles'
                : 'Soldes d’ouverture indisponibles : reclôturez l’exercice '
                    .$this->exerciceService->label($annee - 1).' pour régénérer ses à-nouveaux',
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

    /**
     * Lignes de ventilation sans compte (compte_id NULL). Les lignes PD-only
     * générées par EcritureGenerator (411/401/5XXX) portent toujours un
     * compte_id — ce critère ne cible donc que les lignes métier non ventilées.
     */
    private function checkLignesSansCompte(int $annee): CheckItem
    {
        $count = TransactionLigne::whereHas('transaction', fn ($q) => $q->forExercice($annee))
            ->whereNull('compte_id')
            ->count();

        return new CheckItem(
            nom: 'Lignes sans compte',
            ok: $count === 0,
            message: $count === 0
                ? 'Toutes les lignes ont un compte'
                : "{$count} ligne(s) sans compte",
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
        $count = Transaction::query()
            ->operationnel()
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->whereDoesntHave('rapprochement', fn ($query) => $query
                ->where('statut', StatutRapprochement::Verrouille)
                ->whereDate('date_fin', '<=', $end))
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

    private function checkExerciceCible(int $annee): CheckItem
    {
        if (! config('compta.use_partie_double')) {
            return new CheckItem('Exercice cible', true, 'À-nouveaux non activés en mode comptable historique');
        }

        $cible = Exercice::query()->where('annee', $annee + 1)->first();
        $ok = $cible === null || ! $cible->isCloture();

        return new CheckItem(
            nom: 'Exercice cible',
            ok: $ok,
            message: $ok
                ? 'L’exercice cible est disponible pour les à-nouveaux'
                : 'L’exercice cible '.$this->exerciceService->label($annee + 1).' est déjà clôturé',
        );
    }

    private function checkANouveau(int $annee): CheckItem
    {
        if (! config('compta.use_partie_double')) {
            return new CheckItem('Aperçu AN', true, 'Aperçu AN non requis en mode comptable historique');
        }

        try {
            $preview = app(ANouveauPreviewBuilder::class)->build($annee);
            $ok = $preview->equilibree();

            return new CheckItem(
                nom: 'Aperçu des à-nouveaux',
                ok: $ok,
                message: $ok
                    ? 'L’aperçu des à-nouveaux est équilibré'
                    : 'L’aperçu des à-nouveaux est déséquilibré',
            );
        } catch (Throwable $exception) {
            return new CheckItem(
                nom: 'Aperçu des à-nouveaux',
                ok: false,
                message: 'Impossible de préparer les à-nouveaux : '.$exception->getMessage(),
            );
        }
    }

    private function checkMouvementsExerciceCible(int $annee): CheckItem
    {
        if (! config('compta.use_partie_double')) {
            return new CheckItem('Mouvements exercice suivant', true, 'Contrôle non requis');
        }

        $transactions = Transaction::query()
            ->forExercice($annee + 1)
            ->where('journal', '!=', JournalComptable::AN->value)
            ->count();
        $virements = VirementInterne::query()->forExercice($annee + 1)->count();
        $count = $transactions + $virements;

        return new CheckItem(
            nom: 'Mouvements exercice suivant',
            ok: $count === 0,
            message: $count === 0
                ? 'Aucun mouvement dans l’exercice suivant'
                : "{$count} mouvement(s) déjà présent(s) dans l’exercice suivant ; ils seront conservés",
        );
    }

    /**
     * @return array<string, float>
     */
    private function calculerSoldesComptes(int $annee): array
    {
        $soldes = [];
        $dateFin = $this->exerciceService->dateRange($annee)['end'];
        foreach (CompteBancaire::orderBy('nom')->get() as $compte) {
            $soldes[$compte->nom] = $this->soldeService->solde($compte, $annee, $dateFin);
        }

        return $soldes;
    }
}
