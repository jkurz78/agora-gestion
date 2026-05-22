<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

/**
 * Levée par EcritureGenerator::pourEncaissementCreance lorsque la ligne 411
 * source de la créance porte déjà un lettrage_code — ce qui signifie qu'elle
 * a déjà été encaissée.
 *
 * Distinction avec LettrageDejaPresentException :
 *   - LettrageDejaPresentException est levée par LettrageService::lettrer au
 *     moment de l'appel atomique (invariant de bas niveau).
 *   - LigneDejaLettreeException est levée par EcritureGenerator AVANT d'entrer
 *     dans DB::transaction, pour signaler "la créance source est déjà encaissée"
 *     avec un message métier lisible.
 *
 * Décision actée (Step 17) : deux exceptions distinctes car les contextes
 * sémantiques sont différents (orchestration vs invariant bas niveau).
 */
final class LigneDejaLettreeException extends \DomainException
{
    public static function creanceDejaEncaissee(int $transactionId, int $ligneId, string $code): self
    {
        return new self(
            "La ligne #{$ligneId} de la créance T#{$transactionId} porte déjà le lettrage_code «{$code}» — créance déjà encaissée. Délettrez-la d'abord si nécessaire."
        );
    }
}
