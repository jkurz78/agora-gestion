<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeTransaction: string
{
    case Depense = 'depense';
    case Recette = 'recette';
    case Virement = 'virement';
    case AN = 'an';

    public function label(): string
    {
        return match ($this) {
            self::Depense => 'Dépense',
            self::Recette => 'Recette',
            self::Virement => 'Virement',
            self::AN => 'À-nouveau',
        };
    }
}
