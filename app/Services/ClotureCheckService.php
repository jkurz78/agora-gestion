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
use App\Models\ImmobilisationDotation;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\VirementInterne;
use App\Services\Compta\ANouveau\ANouveauPreviewBuilder;
use App\Services\Compta\EtatComptaResolver;
use App\Services\Immobilisation\DotationService;
use Throwable;

final class ClotureCheckService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly SoldeService $soldeService,
        private readonly EtatComptaResolver $etatCompta,
    ) {}

    public function executer(int $annee): ClotureCheckResult
    {
        $range = $this->exerciceService->dateRange($annee);
        $start = $range['start']->toDateString();
        $end = $range['end']->toDateString();

        return new ClotureCheckResult(
            bloquants: [
                $this->checkPrealablesComptables(),
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
                $this->checkDotationsAmortissements($annee),
                $this->checkVentilationDotations($annee),
            ],
            soldesComptes: $this->calculerSoldesComptes($annee),
        );
    }

    /**
     * Les préalables comptables sont-ils réunis ?
     *
     * Garde ajoutée après la recette du 2026-07-29 : une clôture avait été
     * acceptée sans reprise initiale, produisant une ouverture amputée de
     * 26 000 €. checkOuverturePrecedente ne pouvait pas le voir — elle sort au
     * vert dès que l'exercice précédent n'existe pas, ce qui est le cas de toute
     * première clôture — et checkANouveau ne teste que l'équilibre débit/crédit
     * d'un aperçu par ailleurs incomplet.
     *
     * Le message ne prescrit aucune commande : ces préalables se traitent en
     * administration, et le trésorier qui lit cette checklist n'a pas de console.
     */
    private function checkPrealablesComptables(): CheckItem
    {
        $etat = $this->etatCompta->pourTenantCourant();

        return new CheckItem(
            nom: 'Préalables comptables',
            ok: $etat->estOperationnel(),
            message: $etat->estOperationnel()
                ? 'Les soldes historiques et les écritures sont à jour'
                : $etat->causes().' Ces préalables doivent être traités avant la clôture.',
        );
    }

    public function checkOuverturePrecedente(int $annee): CheckItem
    {
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
        $count = BudgetLine::forExercice($annee)->enveloppes()->count();

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
     * Des dotations aux amortissements restent-elles à générer ?
     *
     * Avertissement et non bloquant : une association sans immobilisation n'a
     * rien à doter, et le trésorier reste maître de l'ordre de ses opérations.
     * Le contrôle est l'endroit naturel où se rappeler que les dotations doivent
     * être générées — puis ventilées — avant de clôturer.
     */
    private function checkDotationsAmortissements(int $annee): CheckItem
    {
        $lignes = app(DotationService::class)->apercu($annee);
        $aGenerer = $lignes->filter(fn ($ligne): bool => $ligne->aGenerer())->count();
        $enEcart = $lignes->filter(fn ($ligne): bool => $ligne->enEcart())->count();

        if ($aGenerer === 0 && $enEcart === 0) {
            return new CheckItem(
                nom: 'Dotations aux amortissements',
                ok: true,
                message: $lignes->isEmpty()
                    ? 'Aucune immobilisation au registre'
                    : 'Les dotations de l’exercice sont à jour',
            );
        }

        $parts = [];
        if ($aGenerer > 0) {
            $parts[] = $aGenerer.' dotation'.($aGenerer > 1 ? 's' : '').' à générer';
        }
        if ($enEcart > 0) {
            $parts[] = $enEcart.' dotation'.($enEcart > 1 ? 's' : '').' à recalculer';
        }

        return new CheckItem(
            nom: 'Dotations aux amortissements',
            ok: false,
            message: implode(', ', $parts).'. Générez-les puis ventilez-les avant de clôturer : '
                .'la ventilation n’est plus possible sur un exercice clôturé.',
        );
    }

    /**
     * Les dotations comptabilisées de l'exercice portent-elles une ventilation
     * analytique sur leur ligne de charge 6811 ?
     *
     * Contrôle séparé de checkDotationsAmortissements() ci-dessus, qui a son
     * propre objet (les dotations sont-elles générées et à jour) : celui-ci
     * vérifie que le message de checkDotationsAmortissements() — « ventilez-les
     * avant de clôturer » — est réellement suivi d'effet, ce qui n'était encore
     * vérifié nulle part.
     *
     * Avertissement, jamais bloquant : une association peut légitimement ne pas
     * ventiler analytiquement ses dotations, le trésorier reste maître de ses
     * arbitrages. Le rôle de ce contrôle est seulement de le rappeler, avant que
     * la clôture ne rende la ventilation définitivement impossible.
     */
    private function checkVentilationDotations(int $annee): CheckItem
    {
        $transactionIds = ImmobilisationDotation::where('exercice', $annee)->pluck('transaction_id');
        $total = $transactionIds->count();

        if ($total === 0) {
            return new CheckItem(
                nom: 'Ventilation des dotations',
                ok: true,
                message: 'Aucune dotation à ventiler pour cet exercice',
            );
        }

        $ventilees = TransactionLigne::whereIn('transaction_id', $transactionIds)
            ->whereHas('compte', fn ($q) => $q->where('numero_pcg', '6811'))
            ->whereHas('affectations')
            ->count();

        $nonVentilees = $total - $ventilees;

        if ($nonVentilees <= 0) {
            return new CheckItem(
                nom: 'Ventilation des dotations',
                ok: true,
                message: 'Toutes les dotations de l’exercice sont ventilées',
            );
        }

        return new CheckItem(
            nom: 'Ventilation des dotations',
            ok: false,
            message: $nonVentilees.' dotation'.($nonVentilees > 1 ? 's' : '').' non ventilée'.($nonVentilees > 1 ? 's' : '')
                .' sur une opération. La ventilation n’est plus possible après la clôture de l’exercice.',
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
