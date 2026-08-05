<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Enums\ModePaiement;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Compta\EcritureGenerator;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Acquisition d'une immobilisation.
 *
 * La fiche est le maître : elle naît avec son écriture dans la même transaction
 * DB, ce qui interdit structurellement la fiche orpheline comme l'acquisition
 * sans fiche.
 *
 * L'écriture est déléguée à la mécanique dépense existante — c'est elle qui
 * apporte la dette 401, le lettrage, le règlement, la remise et le
 * rapprochement, sans une ligne de code de plus ici.
 */
final class ImmobilisationService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly ImmobilisationSequenceService $sequence,
        private readonly ExerciceService $exerciceService,
    ) {}

    public function acquerir(
        Tiers $tiers,
        string $libelle,
        int $quantite,
        Compte $compte,
        Compte $compteAmortissement,
        string $montant,
        \DateTimeInterface $dateAchat,
        \DateTimeInterface $dateMiseEnService,
        int $dureeMois,
        ?ModePaiement $modePaiement,
        ?Compte $compteTresorerie,
        ?string $notes = null,
    ): Immobilisation {
        $this->assertMiseEnServiceCoherente($dateAchat, $dateMiseEnService);

        return DB::transaction(function () use (
            $tiers, $libelle, $quantite, $compte, $compteAmortissement, $montant,
            $dateAchat, $dateMiseEnService, $dureeMois, $modePaiement,
            $compteTresorerie, $notes
        ): Immobilisation {
            $transaction = $this->ecritureGenerator->pourDepenseACredit(
                tiers: $tiers,
                ventilations: [['compte' => $compte, 'montant' => (float) $montant]],
                dateConstatation: $dateAchat,
                libelle: $libelle,
                existingTransaction: null,
                autoriseImmobilisation: true,
            );

            if ($modePaiement !== null && $compteTresorerie !== null) {
                $this->ecritureGenerator->pourReglementFournisseur(
                    transactionDette: $transaction,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    datePaiement: $dateAchat,
                    libelle: 'Règlement '.$libelle,
                );
            }

            return Immobilisation::create([
                'numero' => $this->sequence->prochain(),
                'libelle' => $libelle,
                'quantite' => $quantite,
                'compte_id' => (int) $compte->id,
                'compte_amortissement_id' => (int) $compteAmortissement->id,
                'montant_acquisition' => $montant,
                'date_mise_en_service' => CarbonImmutable::instance(
                    CarbonImmutable::parse($dateMiseEnService->format('Y-m-d'))
                )->toDateString(),
                'duree_mois' => $dureeMois,
                'transaction_id' => (int) $transaction->id,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * La mise en service ne peut pas précéder le début de l'exercice de
     * l'acquisition — sinon on doterait un exercice où le bien n'est pas encore
     * à l'actif. Aucune borne supérieure : la mise en service différée est
     * légitime, et le calculateur la gère par son plancher à 0.
     */
    private function assertMiseEnServiceCoherente(
        \DateTimeInterface $dateAchat,
        \DateTimeInterface $dateMiseEnService,
    ): void {
        $exerciceAchat = $this->exerciceService->anneeForDate(
            CarbonImmutable::parse($dateAchat->format('Y-m-d'))
        );
        $debutExercice = $this->exerciceService->dateRange($exerciceAchat)['start'];

        if (CarbonImmutable::parse($dateMiseEnService->format('Y-m-d'))->lt($debutExercice)) {
            throw MiseEnServiceAnterieureException::pourExercice(
                $dateMiseEnService->format('d/m/Y'),
                $debutExercice->format('d/m/Y'),
            );
        }
    }
}
