<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutCampagne: string
{
    case Brouillon = 'brouillon';
    case Ouverte = 'ouverte';
    case Cloturee = 'cloturee';
    case Archivee = 'archivee';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Ouverte => 'Ouverte',
            self::Cloturee => 'Clôturée',
            self::Archivee => 'Archivée',
        };
    }

    public function peutOuvrir(): bool
    {
        return $this === self::Brouillon;
    }

    public function peutCloturer(): bool
    {
        return $this === self::Ouverte;
    }

    public function accepteReponses(): bool
    {
        return $this === self::Ouverte;
    }
}
