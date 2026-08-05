<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;

final class DotationInterditeException extends RuntimeException
{
    public static function exerciceNonTermine(string $finExercice): self
    {
        return new self(
            "Les dotations ne peuvent être générées qu'une fois l'exercice terminé "
            ."(le {$finExercice}). Le plan d'amortissement reste consultable sur chaque fiche."
        );
    }

    public static function exerciceCloture(int $annee): self
    {
        return new self(
            "L'exercice {$annee} est clôturé : ses dotations ne peuvent plus être "
            .'générées, recalculées ni annulées.'
        );
    }
}
