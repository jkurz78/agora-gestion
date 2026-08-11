<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Services\Compta\EcritureGenerator;
use App\Services\ExerciceService;
use App\Services\TransactionService;
use App\Support\MontantDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Génération, recalcul et annulation des dotations aux amortissements.
 *
 * Invariants de date (spec § 5.2.1) : la date du jour n'intervient ni dans le
 * calcul ni dans l'écriture. Générer en octobre N+1 les dotations de l'exercice
 * N est le cas normal — la clôture des comptes suit la fin de la période.
 *
 * Les gardes vivent ici et non dans l'écran : TransactionForm contraint la date
 * à l'exercice en cours, mais EcritureGenerator ne vérifie rien.
 */
final class DotationService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly PlanAmortissementCalculator $calculator,
        private readonly ExerciceService $exerciceService,
        private readonly TransactionService $transactionService,
    ) {}

    /**
     * Aperçu de l'exercice : une ligne par fiche, sans rien écrire.
     *
     * @return Collection<int, LigneDotationPreview>
     */
    public function apercu(int $exercice): Collection
    {
        return Immobilisation::query()
            ->with(['compte', 'dotations'])
            ->orderBy('numero')
            ->get()
            ->map(function (Immobilisation $immobilisation) use ($exercice): LigneDotationPreview {
                $dotation = $immobilisation->dotations
                    ->firstWhere('exercice', $exercice);

                $cumulAnterieurCentimes = (int) round(
                    ((float) $immobilisation->dotations
                        ->where('exercice', '<', $exercice)
                        ->sum('montant')) * 100
                );

                return new LigneDotationPreview(
                    immobilisation: $immobilisation,
                    moisEcoules: $this->calculator->moisEcoules($immobilisation, $exercice),
                    montantComptabiliseCentimes: $dotation === null
                        ? 0
                        : (int) round(((float) $dotation->montant) * 100),
                    montantRecalculeCentimes: $this->calculator->dotationCentimes(
                        $immobilisation,
                        $exercice,
                        $cumulAnterieurCentimes,
                    ),
                    dejaComptabilisee: $dotation !== null,
                    transactionId: $dotation === null ? null : (int) $dotation->transaction_id,
                );
            });
    }

    /** Génère les dotations manquantes de l'exercice. Idempotent. */
    public function generer(int $exercice): int
    {
        $this->assertExerciceGenerable($exercice);

        $generees = 0;

        foreach ($this->apercu($exercice) as $ligne) {
            if (! $ligne->aGenerer()) {
                continue;
            }

            $this->comptabiliser($ligne->immobilisation, $exercice, $ligne->montantRecalculeCentimes);
            $generees++;
        }

        return $generees;
    }

    /** Annule puis régénère la dotation d'une fiche pour l'exercice donné. */
    public function recalculer(Immobilisation $immobilisation, int $exercice): void
    {
        $this->assertExerciceGenerable($exercice);

        DB::transaction(function () use ($immobilisation, $exercice): void {
            $this->annuler($immobilisation, $exercice);

            $cumulAnterieurCentimes = (int) round(
                ((float) $immobilisation->dotations()
                    ->where('exercice', '<', $exercice)
                    ->sum('montant')) * 100
            );

            $montantCentimes = $this->calculator->dotationCentimes(
                $immobilisation,
                $exercice,
                $cumulAnterieurCentimes,
            );

            if ($montantCentimes > 0) {
                $this->comptabiliser($immobilisation, $exercice, $montantCentimes);
            }
        });
    }

    /**
     * Annule la dotation d'une fiche : purge les lignes et affectations de la
     * transaction (transaction_lignes ne cascade pas depuis son parent, cf.
     * TransactionService::purgerLignesEtAffectations()), puis soft-delete la
     * transaction et supprime la ligne ImmobilisationDotation.
     *
     * Anomalie 2 (audit) — auparavant, seules la transaction et la ligne
     * ImmobilisationDotation étaient supprimées : les transaction_lignes et
     * leurs affectations analytiques restaient actives, d'où des lignes
     * orphelines et des comptes à tort considérés « utilisés ». On ne peut
     * pas passer par TransactionService::delete() : sa garde
     * isLockedByImmobilisation() refuse délibérément toute transaction
     * pilotée par une immobilisation (protège la suppression depuis la liste
     * des transactions — la fiche doit rester l'unique point d'entrée).
     *
     * Anomalie 1 (audit) — refuse si une dotation existe déjà sur un exercice
     * postérieur pour cette fiche : on annule du plus récent au plus ancien,
     * jamais l'inverse (sinon le cumul théorique des exercices suivants,
     * calculé contre un cumul comptabilisé qui vient de perdre un maillon,
     * diverge silencieusement).
     *
     * ATTENTION — si la dotation avait été ventilée sur des opérations, ce
     * travail est perdu et doit être refait. L'appelant DOIT en avertir
     * l'utilisateur.
     */
    public function annuler(Immobilisation $immobilisation, int $exercice): void
    {
        $this->assertExerciceGenerable($exercice);

        $dotation = ImmobilisationDotation::query()
            ->where('immobilisation_id', (int) $immobilisation->id)
            ->where('exercice', $exercice)
            ->first();

        if ($dotation === null) {
            return;
        }

        $dotationPosterieure = ImmobilisationDotation::query()
            ->where('immobilisation_id', (int) $immobilisation->id)
            ->where('exercice', '>', $exercice)
            ->orderBy('exercice')
            ->first();

        if ($dotationPosterieure !== null) {
            throw DotationInterditeException::dotationPosterieureExistante(
                $immobilisation->numero,
                (int) $dotationPosterieure->exercice,
                $exercice,
            );
        }

        DB::transaction(function () use ($dotation): void {
            $transaction = $dotation->transaction;

            if ($transaction !== null) {
                $this->transactionService->purgerLignesEtAffectations($transaction);
                $transaction->delete();
            }

            $dotation->delete();
        });
    }

    private function comptabiliser(Immobilisation $immobilisation, int $exercice, int $montantCentimes): void
    {
        $montant = MontantDecimal::depuisCentimes($montantCentimes);
        $dateEcriture = $this->finExercice($exercice);

        DB::transaction(function () use ($immobilisation, $exercice, $montant, $dateEcriture): void {
            $exerciceManquant = $this->premierExerciceManquant($immobilisation, $exercice);

            if ($exerciceManquant !== null) {
                throw DotationInterditeException::exerciceAnterieurNonGenere(
                    $immobilisation->numero,
                    $exerciceManquant,
                    $exercice,
                );
            }

            $transaction = $this->ecritureGenerator->pourDotationAmortissement(
                immobilisation: $immobilisation,
                date: $dateEcriture,
                montant: $montant,
            );

            ImmobilisationDotation::create([
                'immobilisation_id' => (int) $immobilisation->id,
                'exercice' => $exercice,
                'montant' => $montant,
                'transaction_id' => (int) $transaction->id,
            ]);
        });
    }

    /**
     * Anomalie 1 (audit) — premier exercice, à partir de la mise en service
     * du bien, qui aurait dû recevoir une dotation théorique non nulle mais
     * n'en a aucune enregistrée. Retourne null s'il n'existe aucun trou avant
     * $exerciceCible : soit tous les exercices dus sont dotés, soit aucun
     * exercice antérieur n'était dû (bien mis en service après, ou dotation
     * théorique nulle avant $exerciceCible).
     *
     * Ne s'appuie jamais sur le cumul déjà comptabilisé (c'est justement lui
     * que le désordre fausse) : chaque exercice est jugé sur son incrément
     * théorique propre, cumulTheorique(X) − cumulTheorique(X − 1), qui ne
     * dépend que du calculateur — jamais de l'historique de génération.
     */
    private function premierExerciceManquant(Immobilisation $immobilisation, int $exerciceCible): ?int
    {
        $exerciceMiseEnService = $this->exerciceService->anneeForDate(
            CarbonImmutable::instance($immobilisation->date_mise_en_service->toDateTime())
        );

        for ($exercice = $exerciceMiseEnService; $exercice < $exerciceCible; $exercice++) {
            $incrementTheoriqueCentimes = $this->calculator->cumulTheoriqueCentimes($immobilisation, $exercice)
                - $this->calculator->cumulTheoriqueCentimes($immobilisation, $exercice - 1);

            if ($incrementTheoriqueCentimes <= 0) {
                continue;
            }

            $dotationExiste = ImmobilisationDotation::query()
                ->where('immobilisation_id', (int) $immobilisation->id)
                ->where('exercice', $exercice)
                ->exists();

            if (! $dotationExiste) {
                return $exercice;
            }
        }

        return null;
    }

    /** Dernier jour de l'exercice — jamais now(), jamais une valeur en dur. */
    private function finExercice(int $exercice): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->exerciceService->dateRange($exercice)['end']->toDateString()
        );
    }

    /** Premier jour de l'exercice — jamais now(), jamais une valeur en dur. */
    private function debutExercice(int $exercice): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->exerciceService->dateRange($exercice)['start']->toDateString()
        );
    }

    private function assertExerciceGenerable(int $exercice): void
    {
        $debut = $this->debutExercice($exercice);

        if ($debut->isFuture()) {
            throw DotationInterditeException::exerciceNonCommence($exercice);
        }

        $exerciceModel = Exercice::where('annee', $exercice)->first();

        if ($exerciceModel !== null && $exerciceModel->isCloture()) {
            throw DotationInterditeException::exerciceCloture($exercice);
        }
    }
}
