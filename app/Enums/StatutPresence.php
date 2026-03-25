<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutPresence: string
{
    case Present = 'present';
    case Excuse = 'excuse';
    case AbsenceNonJustifiee = 'absence_non_justifiee';
    case Arret = 'arret';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Présent',
            self::Excuse => 'Excusé',
            self::AbsenceNonJustifiee => 'Abs. non justif.',
            self::Arret => 'Arrêt',
        };
    }
}
