<?php

declare(strict_types=1);

namespace App\Services;

use NumberToWords\NumberToWords;

final class MontantEnLettresService
{
    public function convertir(float $montant): string
    {
        $entier = (int) floor(abs($montant));
        $centimes = (int) round((abs($montant) - $entier) * 100);

        $numberToWords = new NumberToWords;
        $transformer = $numberToWords->getNumberTransformer('fr');

        $partieEntiere = $transformer->toWords($entier);

        // Le million (et au-delà) exige « d'euros » (liaison vocalique) au lieu de « euros »
        $libelleEuros = $this->libelleEuros($entier);
        $resultat = "{$partieEntiere} {$libelleEuros}";

        if ($centimes > 0) {
            $partieCentimes = $transformer->toWords($centimes);
            $libelleCentimes = $centimes === 1 ? 'centime' : 'centimes';
            $resultat .= " et {$partieCentimes} {$libelleCentimes}";
        }

        return $resultat;
    }

    /**
     * Retourne « d'euros » si le montant entier est un multiple de 1 000 000
     * (million, milliard…) pour respecter la liaison vocalique française,
     * et « euros » dans tous les autres cas.
     */
    private function libelleEuros(int $entier): string
    {
        if ($entier >= 1_000_000 && $entier % 1_000_000 === 0) {
            return "d'euros";
        }

        return 'euros';
    }
}
