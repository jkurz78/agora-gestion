<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutInvitation: string
{
    case NonOuvert = 'non_ouvert';
    case Commence = 'commence';
    case Soumis = 'soumis';

    public function label(): string
    {
        return match ($this) {
            self::NonOuvert => 'Non ouvert',
            self::Commence => 'Commencé',
            self::Soumis => 'Soumis',
        };
    }
}
