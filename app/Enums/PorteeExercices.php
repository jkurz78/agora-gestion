<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Portée temporelle du compte de résultat par opérations.
 *
 * Contrôle à deux valeurs, porté dans l'URL sous `exercices=current|all`. Il
 * n'existe volontairement pas de troisième mode ni de liste d'exercices à
 * cocher : choisir `all` élargit la période ET active les lignes d'exercice,
 * d'un seul geste.
 */
enum PorteeExercices: string
{
    case Courant = 'current';
    case Tous = 'all';

    /**
     * Lecture défensive d'une valeur d'URL. Toute valeur inconnue retombe sur
     * `current` — jamais d'erreur, jamais de rapport surprise.
     */
    public static function depuisRequete(?string $valeur): self
    {
        return self::tryFrom((string) $valeur) ?? self::Courant;
    }

    /**
     * Vrai quand le réalisé doit être borné par ExerciceService::dateRange().
     */
    public function estBornee(): bool
    {
        return $this === self::Courant;
    }
}
