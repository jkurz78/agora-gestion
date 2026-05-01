<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeRapprochement: string
{
    case Bancaire = 'bancaire';
    case Lettrage = 'lettrage';

    public function label(): string
    {
        return match ($this) {
            self::Bancaire => 'Bancaire',
            self::Lettrage => 'Lettrage',
        };
    }
}
