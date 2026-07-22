<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutANouveau: string
{
    case Active = 'active';
    case Invalidee = 'invalidee';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Invalidee => 'Invalidée',
        };
    }
}
