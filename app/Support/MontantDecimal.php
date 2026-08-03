<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class MontantDecimal
{
    public static function versCentimes(string $montant): int
    {
        $normalise = trim($montant);
        if (preg_match('/^(?<signe>-?)(?<entier>\d+)(?:\.(?<decimales>\d{1,2}))?$/', $normalise, $matches) !== 1) {
            throw new InvalidArgumentException("Montant décimal invalide : {$montant}.");
        }

        $entier = (int) $matches['entier'];
        $decimales = str_pad($matches['decimales'] ?? '', 2, '0');
        $centimes = ($entier * 100) + (int) $decimales;

        return ($matches['signe'] ?? '') === '-' ? -$centimes : $centimes;
    }

    public static function depuisCentimes(int $centimes): string
    {
        $signe = $centimes < 0 ? '-' : '';
        $valeurAbsolue = abs($centimes);

        return $signe.intdiv($valeurAbsolue, 100).'.'.str_pad(
            (string) ($valeurAbsolue % 100),
            2,
            '0',
            STR_PAD_LEFT,
        );
    }
}
