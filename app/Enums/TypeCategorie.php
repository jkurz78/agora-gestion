<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeCategorie: string
{
    case Depense = 'depense';
    case Recette = 'recette';

    public function label(): string
    {
        return match ($this) {
            self::Depense => 'Dépense',
            self::Recette => 'Recette',
        };
    }
}
