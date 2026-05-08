<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RecuFiscalException extends RuntimeException
{
    public static function associationNonEligible(): self
    {
        return new self('L\'association n\'est pas éligible à l\'émission de reçus fiscaux. Configurez l\'éligibilité dans Paramètres → Association.');
    }

    public static function adresseDonateurManquante(string $champManquant): self
    {
        return new self("Adresse postale du donateur incomplète : {$champManquant} manquant.");
    }

    public static function signataireManquant(): self
    {
        return new self('Le signataire (nom et qualité) doit être configuré dans les paramètres de l\'association.');
    }

    public static function transactionNonEncaissee(): self
    {
        return new self('Un don doit être encaissé pour donner droit à un reçu fiscal.');
    }

    public static function sansSousCategorie(): self
    {
        return new self('La transaction n\'a pas de sous-catégorie associée.');
    }
}
