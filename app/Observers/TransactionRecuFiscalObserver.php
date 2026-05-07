<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\RecuFiscalEmis;
use App\Models\Transaction;
use App\Services\RecuFiscalService;

final class TransactionRecuFiscalObserver
{
    public function __construct(
        private readonly RecuFiscalService $service,
    ) {}

    public function updating(Transaction $transaction): void
    {
        $champsCritiques = ['date', 'tiers_id'];
        $changements = array_intersect($champsCritiques, array_keys($transaction->getDirty()));

        if (empty($changements)) {
            return;
        }

        $ligneIds = $transaction->lignes()->pluck('id');

        $recus = RecuFiscalEmis::query()
            ->whereIn('transaction_ligne_id', $ligneIds)
            ->whereNull('annule_at')
            ->get();

        $detail = implode(', ', $changements);
        foreach ($recus as $recu) {
            $this->service->annuler($recu, "Don modifié — {$detail}");
        }
    }
}
