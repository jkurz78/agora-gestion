<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sens d'une ligne de ventilation passée à EcritureGenerator.
 *
 * Porté explicitement plutôt que déduit du signe du montant : les montants de
 * ventilation sont TOUJOURS strictement positifs, le sens est une information
 * distincte. Une gratuité est une ligne de classe 7 au débit.
 */
enum SensVentilation: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
