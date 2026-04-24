<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutFactureDeposee: string
{
    case Soumise = 'soumise';
    case Traitee = 'traitee';
    case Rejetee = 'rejetee';

    public function label(): string
    {
        return match ($this) {
            self::Soumise => 'Soumise',
            self::Traitee => 'Traitée',
            self::Rejetee => 'Rejetée',
        };
    }
}
