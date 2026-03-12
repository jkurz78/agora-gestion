<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutMembre: string
{
    case Actif = 'actif';
    case Inactif = 'inactif';

    public function label(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Inactif => 'Inactif',
        };
    }
}
