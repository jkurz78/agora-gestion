<?php

declare(strict_types=1);

namespace App\Enums;

enum UsageComptable: string
{
    case Don = 'don';
    case Cotisation = 'cotisation';
    case Inscription = 'inscription';
    case FraisKilometriques = 'frais_kilometriques';
    case AbandonCreance = 'abandon_creance';

    public function label(): string
    {
        return match ($this) {
            self::Don => 'Dons',
            self::Cotisation => 'Cotisations',
            self::Inscription => 'Inscriptions',
            self::FraisKilometriques => 'Indemnités kilométriques',
            self::AbandonCreance => 'Abandon de créance',
        };
    }

    public function polarite(): TypeCategorie
    {
        return match ($this) {
            self::FraisKilometriques => TypeCategorie::Depense,
            self::Don, self::Cotisation, self::Inscription, self::AbandonCreance => TypeCategorie::Recette,
        };
    }

    public function cardinalite(): string
    {
        return match ($this) {
            self::FraisKilometriques, self::AbandonCreance => 'mono',
            self::Don, self::Cotisation, self::Inscription => 'multi',
        };
    }
}
