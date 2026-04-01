<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeDocumentPrevisionnel: string
{
    case Devis = 'devis';
    case Proforma = 'proforma';

    public function label(): string
    {
        return match ($this) {
            self::Devis => 'Devis',
            self::Proforma => 'Pro forma',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Devis => 'D',
            self::Proforma => 'PF',
        };
    }
}
