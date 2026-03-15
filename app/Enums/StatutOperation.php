<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutOperation: string
{
    case EnCours = 'en_cours';
    case Cloturee = 'cloturee';

    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Cloturee => 'Clôturée',
        };
    }
}
