<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutReglement: string
{
    case EnAttente = 'en_attente';
    case Recu = 'recu';
    case Pointe = 'pointe';

    public function label(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Recu => 'Reçu',
            self::Pointe => 'Pointé',
        };
    }

    public function isEncaisse(): bool
    {
        return $this !== self::EnAttente;
    }
}
