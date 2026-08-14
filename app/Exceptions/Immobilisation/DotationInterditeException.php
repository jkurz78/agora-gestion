<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;

final class DotationInterditeException extends RuntimeException
{
    public static function exerciceNonCommence(int $exercice): self
    {
        return new self(
            "L'exercice {$exercice} n'a pas encore commencé : ses dotations ne peuvent pas être générées."
        );
    }

    public static function exerciceCloture(int $annee): self
    {
        return new self(
            "L'exercice {$annee} est clôturé : ses dotations ne peuvent plus être "
            .'générées, recalculées ni supprimées.'
        );
    }

    /**
     * Anomalie 1 (audit) — génération refusée : un exercice antérieur, dû
     * pour cette fiche, n'a pas encore été doté. Générer quand même
     * affecterait la charge au mauvais exercice (cf. docblock DotationService).
     */
    public static function exerciceAnterieurNonGenere(string $numeroFiche, int $exerciceManquant, int $exerciceCible): self
    {
        return new self(
            "Impossible de générer la dotation de {$numeroFiche} pour l'exercice {$exerciceCible} : "
            ."l'exercice {$exerciceManquant} n'a pas encore été doté pour cette fiche. Générez-le d'abord."
        );
    }

    /**
     * Anomalie 1 (audit) — suppression refusée : une dotation existe déjà sur
     * un exercice postérieur pour cette fiche. On supprime du plus récent au
     * plus ancien.
     */
    public static function dotationPosterieureExistante(string $numeroFiche, int $exercicePosterieur, int $exerciceCible): self
    {
        return new self(
            "Impossible de supprimer la dotation de {$numeroFiche} pour l'exercice {$exerciceCible} : "
            ."la dotation de l'exercice {$exercicePosterieur} existe encore pour cette fiche. Supprimez-la d'abord."
        );
    }
}
