<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ExerciceCloturedException extends RuntimeException
{
    public function __construct(int $annee)
    {
        parent::__construct("L'exercice {$annee}-".($annee + 1).' est clôturé. Aucune modification n\'est autorisée.');
    }
}
