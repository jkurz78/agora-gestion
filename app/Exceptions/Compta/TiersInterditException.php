<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

/**
 * Levée quand une ligne d'écriture sur un compte bancaire physique (512X)
 * porte un tiers_id, ce qui est interdit.
 *
 * Les comptes 512X ne portent jamais de tiers : le cycle de pointage est
 * le rapprochement bancaire, pas le lettrage. La dimension tiers sur ces
 * comptes serait une seconde source de vérité redondante.
 */
final class TiersInterditException extends \DomainException
{
    public static function surCompte512(string $numeroPcg): self
    {
        return new self(
            "Le compte bancaire {$numeroPcg} ne doit pas porter de tiers_id."
        );
    }
}
