<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

/**
 * Levée quand une ligne d'écriture sur le compte 411 (Clients) ou 401
 * (Fournisseurs) ne porte pas de tiers_id.
 *
 * Ces comptes de tiers nécessitent impérativement un axe tiers pour permettre
 * le grand livre tiers et la génération du FEC (colonnes CompAuxNum/CompAuxLib).
 */
final class TiersRequisException extends \DomainException
{
    public static function surCompte(string $numeroPcg): self
    {
        return new self(
            "Le compte {$numeroPcg} requiert un tiers_id sur chaque ligne d'écriture."
        );
    }
}
