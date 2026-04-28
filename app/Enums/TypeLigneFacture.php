<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeLigneFacture: string
{
    case Montant = 'montant';
    case MontantLibre = 'montant_libre';
    case Texte = 'texte';

    /** True uniquement pour MontantLibre — génère une TransactionLigne à la validation. */
    public function genereTransactionLigne(): bool
    {
        return $this === self::MontantLibre;
    }

    /** True pour les lignes ayant un impact comptable (Montant ref et MontantLibre). */
    public function aImpactComptable(): bool
    {
        return $this === self::Montant || $this === self::MontantLibre;
    }
}
