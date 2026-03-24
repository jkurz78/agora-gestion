<?php

declare(strict_types=1);

namespace App\Enums;

enum Espace: string
{
    case Compta = 'compta';
    case Gestion = 'gestion';

    public function label(): string
    {
        return match ($this) {
            self::Compta => 'Comptabilité',
            self::Gestion => 'Gestion',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Compta => '#722281',
            self::Gestion => '#A9014F',
        };
    }
}
