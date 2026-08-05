<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Models\Immobilisation;

/**
 * Une ligne de l'écran « Dotations de l'exercice ».
 *
 * L'écart est dérivé de la comparaison entre le montant comptabilisé et le
 * montant recalculé — il n'existe aucun indicateur « dirty » stocké, donc rien
 * qui puisse se désynchroniser.
 */
final class LigneDotationPreview
{
    public function __construct(
        public readonly Immobilisation $immobilisation,
        public readonly int $moisEcoules,
        public readonly int $montantComptabiliseCentimes,
        public readonly int $montantRecalculeCentimes,
        public readonly bool $dejaComptabilisee,
    ) {}

    public function enEcart(): bool
    {
        return $this->dejaComptabilisee
            && $this->montantComptabiliseCentimes !== $this->montantRecalculeCentimes;
    }

    public function aGenerer(): bool
    {
        return ! $this->dejaComptabilisee && $this->montantRecalculeCentimes > 0;
    }
}
