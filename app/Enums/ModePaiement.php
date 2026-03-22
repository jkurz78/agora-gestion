<?php

declare(strict_types=1);

namespace App\Enums;

enum ModePaiement: string
{
    case Virement = 'virement';
    case Cheque = 'cheque';
    case Especes = 'especes';
    case Cb = 'cb';
    case Prelevement = 'prelevement';

    public function label(): string
    {
        return match ($this) {
            self::Virement => 'Virement',
            self::Cheque => 'Chèque',
            self::Especes => 'Espèces',
            self::Cb => 'Carte bancaire',
            self::Prelevement => 'Prélèvement',
        };
    }
}
