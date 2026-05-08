<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Adhesion;
use App\Models\TransactionLigne;
use App\Services\AdhesionService;

final class AdhesionTransactionLigneObserver
{
    public function __construct(
        private readonly AdhesionService $service,
    ) {}

    /**
     * A TransactionLigne was created or updated: re-evaluate whether the parent
     * transaction now qualifies for an adhesion. The service is idempotent.
     */
    public function saved(TransactionLigne $ligne): void
    {
        $tx = $ligne->transaction;

        if ($tx === null) {
            return;
        }

        $this->service->creerDepuisTransaction($tx);
    }

    /**
     * A TransactionLigne was soft-deleted: if the parent transaction no longer has
     * any cotisation ligne, soft-delete the associated non-gratuite adhesion.
     */
    public function deleted(TransactionLigne $ligne): void
    {
        $tx = $ligne->transaction;

        if ($tx === null) {
            return;
        }

        $stillHasCotisation = $this->service->creerDepuisTransaction($tx);

        if ($stillHasCotisation === null) {
            Adhesion::where('transaction_id', $tx->id)
                ->where('gratuite', false)
                ->delete();
        }
    }
}
