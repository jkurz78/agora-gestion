<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

use App\Services\Compta\EtatCompta;
use RuntimeException;

/**
 * Refus d'une opération dont les préalables comptables ne sont pas réunis.
 *
 * Le message nomme la cause, jamais le remède : celui-ci dépend du support et du
 * tenant, c'est à l'appelant de le composer.
 */
final class EtapeComptaRequiseException extends RuntimeException
{
    private function __construct(string $message, public readonly EtatCompta $etat)
    {
        parent::__construct($message);
    }

    public static function pour(EtatCompta $etat): self
    {
        return new self($etat->etape()->label().' — '.$etat->causes(), $etat);
    }
}
