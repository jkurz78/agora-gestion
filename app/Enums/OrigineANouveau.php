<?php

declare(strict_types=1);

namespace App\Enums;

enum OrigineANouveau: string
{
    case Cloture = 'cloture';
    case RepriseInitiale = 'reprise_initiale';

    public function label(): string
    {
        return match ($this) {
            self::Cloture => 'Clôture d’exercice',
            self::RepriseInitiale => 'Reprise initiale',
        };
    }
}
