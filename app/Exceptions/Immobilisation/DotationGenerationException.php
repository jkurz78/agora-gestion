<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;
use Throwable;

/**
 * Anomalie 4 (audit) — un échec technique (pas une règle métier violée, ce
 * qui reste du ressort de DotationInterditeException) survenu au milieu d'un
 * lot de génération. Nomme la fiche fautive pour rester actionnable : le lot
 * entier est de toute façon annulé (DotationService::generer() ouvre une
 * seule transaction pour tout le lot), donc savoir laquelle a bloqué est la
 * seule information qui reste utile à l'appelant.
 */
final class DotationGenerationException extends RuntimeException
{
    public static function pourFiche(string $numeroFiche, int $exercice, Throwable $previous): self
    {
        return new self(
            "La génération des dotations de l'exercice {$exercice} a échoué sur la fiche "
            ."{$numeroFiche} : {$previous->getMessage()} Aucune dotation de ce lot n'a été enregistrée.",
            previous: $previous,
        );
    }
}
