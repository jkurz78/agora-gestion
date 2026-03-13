<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutRapprochement: string
{
    case EnCours = 'en_cours';
    case Verrouille = 'verrouille';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Verrouille => 'Verrouillé',
        };
    }
}
