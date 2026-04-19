<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutNoteDeFrais: string
{
    case Brouillon = 'brouillon';
    case Soumise = 'soumise';
    case Rejetee = 'rejetee';
    case Validee = 'validee';
    case Payee = 'payee';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumise => 'Soumise',
            self::Rejetee => 'Rejetée',
            self::Validee => 'Validée',
            self::Payee => 'Payée',
        };
    }
}
