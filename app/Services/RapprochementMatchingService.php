<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RapprochementMatchingProposition;
use App\DTOs\RapprochementMatchingResult;
use App\DTOs\ReleveOcrMouvement;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Throwable;

final class RapprochementMatchingService
{
    // Scoring constants — easily adjustable after calibration
    private const SCORE_DATE_SAME_DAY = 10;

    private const SCORE_DATE_1_DAY = 8;

    private const SCORE_DATE_3_DAYS = 5;

    private const SCORE_DATE_7_DAYS = 2;

    private const SCORE_DATE_BEYOND = 0;

    private const SCORE_LIBELLE_MAX = 10;

    private const SCORE_MINIMUM = 3;

    private const SCORE_UNIQUE_MATCH = 20;

    /**
     * Rapproche les mouvements d'un relevé bancaire avec les transactions
     * comptables non pointées.
     *
     * Pour chaque mouvement, on ne considère que les transactions dont le
     * montant correspond exactement. S'il n'y en a qu'une, elle est retenue
     * d'office (score maximal). S'il y en a plusieurs, elles sont départagées
     * par proximité de date et de libellé ; en-dessous du score minimum, le
     * mouvement reste non apparié plutôt que d'être matché au hasard.
     *
     * @param  array<ReleveOcrMouvement>  $mouvements
     * @param  Collection<int, array{id: int, type: string, date: string, libelle: ?string, montant_signe: float}>  $transactions  transactions non pointées candidates au rapprochement
     */
    public function matcher(array $mouvements, Collection $transactions): RapprochementMatchingResult
    {
        $pool = $transactions->values();

        $propositions = [];
        $nonApparies = [];

        foreach ($mouvements as $mouvement) {
            $candidats = $pool->filter(
                fn (array $candidate): bool => abs(round($mouvement->montant, 2) - round($candidate['montant_signe'], 2)) < 0.001
            );

            if ($candidats->isEmpty()) {
                $nonApparies[] = $mouvement;

                continue;
            }

            if ($candidats->count() === 1) {
                $candidat = $candidats->first();

                $propositions[] = $this->creerProposition($mouvement, $candidat, self::SCORE_UNIQUE_MATCH);

                $pool = $pool->reject(fn (array $item): bool => $item === $candidat);

                continue;
            }

            $meilleurCandidat = null;
            $meilleurScore = null;

            foreach ($candidats as $candidat) {
                $score = $this->scoreDate($mouvement->date, $candidat['date'])
                    + $this->scoreLibelle($mouvement->libelle, $candidat['libelle']);

                if ($meilleurScore === null || $score > $meilleurScore) {
                    $meilleurScore = $score;
                    $meilleurCandidat = $candidat;
                }
            }

            if ($meilleurCandidat === null || $meilleurScore < self::SCORE_MINIMUM) {
                $nonApparies[] = $mouvement;

                continue;
            }

            $propositions[] = $this->creerProposition($mouvement, $meilleurCandidat, $meilleurScore);

            $pool = $pool->reject(fn (array $item): bool => $item === $meilleurCandidat);
        }

        return new RapprochementMatchingResult(propositions: $propositions, nonApparies: $nonApparies);
    }

    /**
     * @param  array{id: int, type: string, date: string, libelle: ?string, montant_signe: float}  $candidat
     */
    private function creerProposition(ReleveOcrMouvement $mouvement, array $candidat, int $score): RapprochementMatchingProposition
    {
        return new RapprochementMatchingProposition(
            mouvement_date: $mouvement->date,
            mouvement_libelle: $mouvement->libelle,
            mouvement_montant: $mouvement->montant,
            transaction_id: (int) $candidat['id'],
            transaction_type: (string) $candidat['type'],
            score: $score,
        );
    }

    private function scoreDate(?string $dateReleve, ?string $dateTransaction): int
    {
        if ($dateReleve === null || $dateTransaction === null) {
            return 0;
        }

        try {
            $diffJours = (int) abs(
                (new DateTimeImmutable($dateReleve))->diff(new DateTimeImmutable($dateTransaction))->days
            );
        } catch (Throwable) {
            return 0;
        }

        return match (true) {
            $diffJours === 0 => self::SCORE_DATE_SAME_DAY,
            $diffJours <= 1 => self::SCORE_DATE_1_DAY,
            $diffJours <= 3 => self::SCORE_DATE_3_DAYS,
            $diffJours <= 7 => self::SCORE_DATE_7_DAYS,
            default => self::SCORE_DATE_BEYOND,
        };
    }

    private function scoreLibelle(?string $libelleReleve, ?string $libelleTransaction): int
    {
        if ($libelleReleve === null || $libelleTransaction === null) {
            return 0;
        }

        $libelleReleveNormalise = trim(mb_strtolower($libelleReleve));
        $libelleTransactionNormalise = trim(mb_strtolower($libelleTransaction));

        if ($libelleReleveNormalise === '' || $libelleTransactionNormalise === '') {
            return 0;
        }

        similar_text($libelleReleveNormalise, $libelleTransactionNormalise, $percent);

        return (int) round(($percent / 100) * self::SCORE_LIBELLE_MAX);
    }
}
