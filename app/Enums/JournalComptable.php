<?php

declare(strict_types=1);

namespace App\Enums;

enum JournalComptable: string
{
    case Vente = 'vente';
    case Achat = 'achat';
    case Banque = 'banque';
    case Od = 'od';

    public function label(): string
    {
        return match ($this) {
            self::Vente => 'Journal des ventes',
            self::Achat => 'Journal des achats',
            self::Banque => 'Journal de banque',
            self::Od => 'Journal des opérations diverses',
        };
    }
}
