<?php

declare(strict_types=1);

namespace App\Enums;

enum Civilite: string
{
    case M = 'M.';
    case Mme = 'Mme';

    public function label(): string
    {
        return match ($this) {
            self::M => 'Monsieur',
            self::Mme => 'Madame',
        };
    }
}
