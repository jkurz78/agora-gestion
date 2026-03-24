<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutExercice: string
{
    case Ouvert = 'ouvert';
    case Cloture = 'cloture';

    public function label(): string
    {
        return match ($this) {
            self::Ouvert => 'Ouvert',
            self::Cloture => 'Clôturé',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Ouvert => 'bg-success',
            self::Cloture => 'bg-secondary',
        };
    }
}
