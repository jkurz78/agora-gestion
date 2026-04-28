<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeLigneFacture: string
{
    case Montant = 'montant';
    case MontantManuel = 'montant_manuel';
    case Texte = 'texte';

    /** True uniquement pour MontantManuel — génère une TransactionLigne à la validation. */
    public function genereTransactionLigne(): bool
    {
        return $this === self::MontantManuel;
    }

    /** True pour les lignes ayant un impact comptable (Montant ref et MontantManuel). */
    public function aImpactComptable(): bool
    {
        return $this === self::Montant || $this === self::MontantManuel;
    }
}
