<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sens d'une écriture comptable : Recette ou Dépense.
 *
 * Remplace le paramètre `bool $isDepense` de CompteTresorerieResolver::resoudre
 * (Vague 3b Item E — anti-pattern bool flag).
 */
enum Sens
{
    case Recette;
    case Depense;
}
