<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;

final class MiseEnServiceAnterieureException extends RuntimeException
{
    public static function pourExercice(string $miseEnService, string $debutExercice): self
    {
        return new self(
            "La date de mise en service ({$miseEnService}) ne peut pas précéder le début de "
            ."l'exercice de l'acquisition ({$debutExercice}) : le bien serait amorti avant "
            .'son entrée à l’actif.'
        );
    }
}
