<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeLigneFacture: string
{
    case Montant = 'montant';
    case Texte = 'texte';
}
