<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeActionExercice: string
{
    case Creation = 'creation';
    case Cloture = 'cloture';
    case Reouverture = 'reouverture';

    public function label(): string
    {
        return match ($this) {
            self::Creation => 'Création',
            self::Cloture => 'Clôture',
            self::Reouverture => 'Réouverture',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Creation => 'bg-success',
            self::Cloture => 'bg-danger',
            self::Reouverture => 'bg-warning text-dark',
        };
    }
}
