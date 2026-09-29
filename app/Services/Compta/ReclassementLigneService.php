<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\RecuFiscalEmis;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reclassement d'une ligne de ventilation : corrige l'imputation (compte,
 * opération, séance) d'une écriture déjà réglée, sans toucher au montant ni à
 * la contrepartie 411/401.
 *
 * Doctrine tranchée le 2026-09-29 : reclassement EN PLACE, pas d'écriture
 * miroir. Ce que l'écriture miroir aurait apporté — la trace — est porté par la
 * ligne elle-même : compte d'origine, motif, date, auteur. La marque
 * (`reclassee_at`) protège ensuite la correction de la resynchronisation
 * HelloAsso, qui réécrirait sinon le compte sans le dire.
 *
 * Les colonnes `debit`, `credit` et `montant` ne sont JAMAIS réécrites : une
 * ligne produite par EcritureGenerator porte son montant dans `debit` / `credit`
 * (avec `montant = 0`), et une contre-écriture au débit (gratuité 709A) reste
 * au débit même sur une recette. Recalculer depuis `montant` mettrait
 * l'écriture à zéro ; recalculer depuis le type de la pièce retournerait la
 * contre-écriture. Changer de compte de produit ne change pas le sens.
 */
final class ReclassementLigneService
{
    private const MOTIF_MIN = 10;

    private const MOTIF_MAX = 255;

    public function __construct(private readonly ExerciceService $exerciceService) {}

    public function reclasser(
        TransactionLigne $ligne,
        int $compteId,
        ?int $operationId,
        ?int $seance,
        string $motif,
        User $auteur,
    ): TransactionLigne {
        $motif = trim($motif);
        if (mb_strlen($motif) < self::MOTIF_MIN) {
            throw new RuntimeException('Indiquez le motif du reclassement ('.self::MOTIF_MIN.' caractères minimum).');
        }
        if (mb_strlen($motif) > self::MOTIF_MAX) {
            throw new RuntimeException('Le motif ne peut pas dépasser '.self::MOTIF_MAX.' caractères.');
        }

        $transaction = $ligne->transaction;
        if ($transaction === null) {
            throw new RuntimeException('Cette ligne n\'est rattachée à aucune transaction.');
        }

        $classeAttendue = match ($transaction->type) {
            TypeTransaction::Recette => 7,
            TypeTransaction::Depense => 6,
            default => throw new RuntimeException('Seule une recette ou une dépense peut être reclassée.'),
        };

        $comptePrecedent = null;
        $compte = null;

        DB::transaction(function () use ($ligne, $transaction, $classeAttendue, $compteId, $operationId, $seance, $motif, $auteur, &$comptePrecedent, &$compte): void {
            // Un reclassement réécrit le grand livre de l'exercice de la pièce :
            // même verrou que TransactionService::update(), pour qu'une clôture
            // concurrente ne s'intercale pas entre le contrôle et l'écriture.
            $this->exerciceService->assertOuvertVerrouille(
                $this->exerciceService->anneeForDate(CarbonImmutable::parse($transaction->date))
            );

            // Seule une imputation de produit (ou de charge) se reclasse : jamais
            // une contrepartie 411/401 ni une ligne de trésorerie.
            $compteActuel = $ligne->compte;
            if ($compteActuel === null || (int) $compteActuel->classe !== $classeAttendue) {
                throw new RuntimeException('Seule une ligne de '.($classeAttendue === 7 ? 'produit' : 'charge').' peut être reclassée.');
            }

            // Compte et opération sont résolus sous le scope tenant : un
            // identifiant d'une autre association est traité comme inexistant.
            $compte = Compte::find($compteId);
            if ($compte === null || (int) $compte->classe !== $classeAttendue || ! $compte->actif) {
                throw new RuntimeException("Choisissez un compte actif de classe {$classeAttendue}.");
            }

            if ($operationId !== null && Operation::find($operationId) === null) {
                throw new RuntimeException('Cette opération n\'existe pas.');
            }

            if (RecuFiscalEmis::where('transaction_ligne_id', (int) $ligne->id)
                ->whereNull('annule_at')
                ->exists()) {
                throw new RuntimeException('Un reçu fiscal a été émis pour cette ligne — annulez-le avant de la reclasser.');
            }

            $comptePrecedent = $compteActuel->numero_pcg;

            // Le compte d'origine est celui d'avant le PREMIER reclassement :
            // un second reclassement ne l'écrase pas.
            $compteOrigineId = $ligne->reclassement_compte_origine_id !== null
                ? (int) $ligne->reclassement_compte_origine_id
                : (int) $ligne->compte_id;

            $ligne->forceFill([
                'compte_id' => (int) $compte->id,
                'operation_id' => $operationId,
                'seance' => $seance,
                'reclassement_compte_origine_id' => $compteOrigineId,
                'reclassement_motif' => $motif,
                'reclassee_at' => now(),
                'reclassee_par_user_id' => (int) $auteur->id,
            ])->save();

            // Dans la transaction : un grand livre déséquilibré annule tout.
            PartieDoubleGuard::assertComplete($transaction->fresh());
        });

        Log::info('[Reclassement] imputation corrigée à la main', [
            'transaction_id' => (int) $transaction->id,
            'ligne_id' => (int) $ligne->id,
            'compte_avant' => $comptePrecedent,
            'compte_apres' => $compte?->numero_pcg,
            'operation_id' => $operationId,
            'motif' => $motif,
        ]);

        return $ligne->fresh();
    }
}
