<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Exceptions\Compta\PartieDoubleIncompleteException;
use App\Models\Transaction;

final class PartieDoubleGuard
{
    public static function assertComplete(Transaction $tx): void
    {
        if (! config('compta.use_partie_double')) {
            return;
        }

        if ($tx->helloasso_order_id !== null) {
            return;
        }

        if ($tx->equilibree !== true) {
            throw PartieDoubleIncompleteException::nonEquilibree((int) $tx->id);
        }

        $lignesPD = $tx->lignes()->whereNotNull('compte_id')->get();

        if ($lignesPD->isEmpty()) {
            throw PartieDoubleIncompleteException::sansLignes((int) $tx->id);
        }

        $totalDebit = round((float) $lignesPD->sum('debit'), 2);
        $totalCredit = round((float) $lignesPD->sum('credit'), 2);

        if ($totalDebit !== $totalCredit) {
            throw PartieDoubleIncompleteException::desequilibree(
                (int) $tx->id,
                number_format($totalDebit, 2, '.', ''),
                number_format($totalCredit, 2, '.', ''),
            );
        }
    }
}
