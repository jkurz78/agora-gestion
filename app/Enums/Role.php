<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Comptable = 'comptable';
    case Gestionnaire = 'gestionnaire';
    case Consultation = 'consultation';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Comptable => 'Comptable',
            self::Gestionnaire => 'Gestionnaire',
            self::Consultation => 'Consultation',
        };
    }

    public function canRead(Espace $espace): bool
    {
        return true;
    }

    public function canWrite(Espace $espace): bool
    {
        return match ($this) {
            self::Admin => true,
            self::Comptable => $espace === Espace::Compta,
            self::Gestionnaire => $espace === Espace::Gestion,
            self::Consultation => false,
        };
    }

    public function canAccessParametres(): bool
    {
        return $this === self::Admin;
    }
}
