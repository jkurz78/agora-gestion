<?php

declare(strict_types=1);

namespace App\Enums;

enum StatutFacture: string
{
    case Brouillon = 'brouillon';
    case Validee = 'validee';
    case Annulee = 'annulee';
}
