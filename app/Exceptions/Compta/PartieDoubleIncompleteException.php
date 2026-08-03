<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class PartieDoubleIncompleteException extends \RuntimeException
{
    public static function nonEquilibree(int $transactionId): self
    {
        return new self("Transaction #{$transactionId} : mode PD actif mais equilibree=false.");
    }

    public static function sansLignes(int $transactionId): self
    {
        return new self("Transaction #{$transactionId} : mode PD actif mais aucune ligne comptable (compte_id).");
    }

    public static function desequilibree(int $transactionId, string $debit, string $credit): self
    {
        return new self("Transaction #{$transactionId} : PD déséquilibrée (debit={$debit}, credit={$credit}).");
    }
}
