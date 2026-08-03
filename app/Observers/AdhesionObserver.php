<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Adhesion;
use App\Models\Transaction;
use App\Services\AdhesionService;

final class AdhesionObserver
{
    public function __construct(
        private readonly AdhesionService $service,
    ) {}

    /**
     * Transaction updated: re-evaluate cotisation status (idempotent via service).
     * Note: Transaction::created fires BEFORE lignes are inserted (factory afterCreating
     * runs after the created event). Adhesion creation is therefore handled by
     * AdhesionTransactionLigneObserver::saved instead.
     */
    public function updated(Transaction $tx): void
    {
        if (AdhesionTransactionLigneObserver::$suppress) {
            return;
        }

        $this->service->creerDepuisTransaction($tx);
    }

    /**
     * Soft-delete the adhesion in mirror of the transaction.
     * Only affects adhesions backed by a transaction (offered adhesions have no transaction_id, so the where clause naturally excludes them).
     */
    public function deleted(Transaction $tx): void
    {
        Adhesion::where('transaction_id', $tx->id)
            ->whereNotNull('transaction_id')
            ->delete();
    }

    /**
     * Restore the adhesion in mirror of the restored transaction.
     */
    public function restored(Transaction $tx): void
    {
        Adhesion::onlyTrashed()
            ->where('transaction_id', $tx->id)
            ->whereNotNull('transaction_id')
            ->restore();
    }
}
