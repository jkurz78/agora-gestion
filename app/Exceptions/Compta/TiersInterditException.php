<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

/**
 * Levée quand une ligne d'écriture sur un compte de classe 5 (512X, 5112, 530…)
 * porte un tiers_id, ce qui est interdit (spec §4.2 invariant 5 amendé 2026-05-22).
 *
 * Les comptes de trésorerie (classe 5) ne portent jamais de tiers, conformément
 * à la norme FEC française (BOI-CF-IOR-60-40-20 : CompAuxNum réservé à la classe 4).
 * Le tiers vit exclusivement sur les comptes auxiliaires 411 et 401.
 */
final class TiersInterditException extends \DomainException
{
    public static function surCompteClasse5(string $numeroPcg): self
    {
        return new self(
            "Le compte de trésorerie {$numeroPcg} (classe 5) ne doit pas porter de tiers_id."
        );
    }
}
