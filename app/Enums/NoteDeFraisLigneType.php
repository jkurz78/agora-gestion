<?php

declare(strict_types=1);

namespace App\Enums;

enum NoteDeFraisLigneType: string
{
    case Standard = 'standard';
    case Kilometrique = 'kilometrique';
}
