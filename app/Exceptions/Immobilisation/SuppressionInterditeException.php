<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;

final class SuppressionInterditeException extends RuntimeException
{
    public static function dotationsExistantes(string $numero): self
    {
        return new self(
            "La fiche {$numero} porte déjà des dotations aux amortissements comptabilisées : "
            .'annulez-les depuis l’écran des dotations avant de supprimer la fiche.'
        );
    }
}
