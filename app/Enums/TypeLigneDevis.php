<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeLigneDevis: string
{
    case Montant = 'montant';
    case Texte = 'texte';

    public function label(): string
    {
        return match ($this) {
            self::Montant => 'Montant',
            self::Texte => 'Texte',
        };
    }
}
