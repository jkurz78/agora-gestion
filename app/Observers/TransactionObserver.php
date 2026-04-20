<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

final class TransactionObserver
{
    /**
     * Cache the linked NDF before deletion so that the DB-level nullOnDelete
     * constraint on notes_de_frais.transaction_id does not prevent us from
     * finding it after the physical row is removed.
     */
    public function deleting(Transaction $transaction): void
    {
        $ndf = NoteDeFrais::where('transaction_id', $transaction->id)->first();

        if ($ndf !== null) {
            $transaction->setRelation('pendingNdfRevert', $ndf);
        }
    }

    public function deleted(Transaction $transaction): void
    {
        $this->revertLinkedNdf($transaction);
    }

    /**
     * forceDelete() calls delete() internally, so `deleted` already fires and
     * handles the revert. This hook exists for direct forceDelete paths that
     * might bypass the soft-delete event chain in future Laravel versions.
     */
    public function forceDeleted(Transaction $transaction): void
    {
        // Already handled by `deleted` since forceDelete() calls delete() internally.
    }

    private function revertLinkedNdf(Transaction $transaction): void
    {
        if (! $transaction->relationLoaded('pendingNdfRevert')) {
            return;
        }

        /** @var NoteDeFrais $ndf */
        $ndf = $transaction->getRelation('pendingNdfRevert');

        $ndf->update([
            'statut' => StatutNoteDeFrais::Soumise->value,
            'transaction_id' => null,
            'validee_at' => null,
        ]);

        Log::info('comptabilite.ndf.reverted_to_submitted', [
            'ndf_id' => $ndf->id,
            'transaction_id' => $transaction->id,
        ]);
    }
}
