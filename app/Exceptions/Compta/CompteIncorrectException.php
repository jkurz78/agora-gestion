<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

/**
 * Levée quand un compte passé à EcritureGenerator ne correspond pas à la
 * classe PCG attendue pour l'opération demandée.
 *
 * Ex : passer un compte classe 6 là où un compte classe 7 (produit) est
 * attendu, ou passer un compte de trésorerie (classe 5) là où un compte de
 * gestion est requis.
 *
 * Utilisée à partir des Steps 15-20 (méthodes pour*).
 */
final class CompteIncorrectException extends \DomainException
{
    public static function classeAttendue(string $numeroPcg, int $classeRecu, int|string $classeAttendue): self
    {
        return new self(
            "Le compte {$numeroPcg} est de classe {$classeRecu}, classe {$classeAttendue} attendue."
        );
    }

    public static function numeroInconnu(string $numeroPcg): self
    {
        return new self(
            "Le compte {$numeroPcg} n'est pas reconnu pour cette opération."
        );
    }
}
