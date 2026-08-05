<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Services\Compta\EcritureGenerator;
use App\Services\ExerciceService;
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
     * Annule la dotation d'une fiche : soft-delete de la transaction et
     * suppression de la ligne.
     *
     * ATTENTION — la transaction supprimée emporte ses affectations
     * analytiques. Si la dotation avait été ventilée sur des opérations, ce
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

        DB::transaction(function () use ($dotation): void {
            $dotation->transaction?->delete();
            $dotation->delete();
        });
    }

    private function comptabiliser(Immobilisation $immobilisation, int $exercice, int $montantCentimes): void
    {
        $montant = MontantDecimal::depuisCentimes($montantCentimes);
        $dateEcriture = $this->finExercice($exercice);

        DB::transaction(function () use ($immobilisation, $exercice, $montant, $dateEcriture): void {
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

    /** Dernier jour de l'exercice — jamais now(), jamais une valeur en dur. */
    private function finExercice(int $exercice): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->exerciceService->dateRange($exercice)['end']->toDateString()
        );
    }

    private function assertExerciceGenerable(int $exercice): void
    {
        $fin = $this->finExercice($exercice);

        if ($fin->isFuture()) {
            throw DotationInterditeException::exerciceNonTermine($fin->format('d/m/Y'));
        }

        $exerciceModel = Exercice::where('annee', $exercice)->first();

        if ($exerciceModel !== null && $exerciceModel->isCloture()) {
            throw DotationInterditeException::exerciceCloture($exercice);
        }
    }
}
