<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;

final class CompteImmobilisationInvalideException extends RuntimeException
{
    public static function classeInvalide(string $numeroPcg, int $classe): self
    {
        return new self(
            "Le compte {$numeroPcg} est de classe {$classe} : seul un compte de classe 2 "
            .'peut porter une immobilisation.'
        );
    }

    public static function estCompteAmortissement(string $numeroPcg): self
    {
        return new self(
            "Le compte {$numeroPcg} est un compte d'amortissement : il ne peut pas être "
            .'utilisé comme compte d’immobilisation.'
        );
    }

    public static function amortissementIncorrect(string $numeroCompte, string $numeroAttendu, string $numeroRecu): self
    {
        return new self(
            "Le compte d'amortissement {$numeroRecu} ne correspond pas au compte "
            ."d'immobilisation {$numeroCompte} : le compte attendu est {$numeroAttendu}."
        );
    }

    public static function horsTenant(string $numeroPcg): self
    {
        return new self(
            "Le compte {$numeroPcg} n'appartient pas à cette association : il ne peut pas "
            .'être utilisé pour une immobilisation.'
        );
    }

    public static function inactif(string $numeroPcg): self
    {
        return new self(
            "Le compte {$numeroPcg} est inactif : il ne peut pas être utilisé pour une "
            .'nouvelle immobilisation.'
        );
    }
}
