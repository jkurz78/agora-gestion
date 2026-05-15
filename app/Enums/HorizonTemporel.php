<?php

declare(strict_types=1);

namespace App\Enums;

enum HorizonTemporel
{
    case AVenir;
    case EnCours;
    case Terminee;

    public function label(): string
    {
        return match ($this) {
            self::AVenir => 'À venir',
            self::EnCours => 'En cours',
            self::Terminee => 'Terminée',
        };
    }
}
