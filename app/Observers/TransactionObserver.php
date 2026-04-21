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
     * Cache the linked NDF before deletion so that DB-level nullOnDelete
     * constraints on notes_de_frais.transaction_id / don_transaction_id do not
     * prevent us from finding it after the physical row is removed.
     *
     * We also resolve the "sister" transaction (the other half of an abandon de
     * créance pair) so we can soft-delete it together with the one being removed.
     */
    public function deleting(Transaction $transaction): void
    {
        $ndf = NoteDeFrais::where('transaction_id', $transaction->id)
            ->orWhere('don_transaction_id', $transaction->id)
            ->first();

        if ($ndf !== null) {
            $transaction->setRelation('pendingNdfRevert', $ndf);

            // Identify the sister transaction (the other FK in the abandon pair).
            $sisterId = ((int) $ndf->transaction_id === (int) $transaction->id)
                ? $ndf->don_transaction_id
                : $ndf->transaction_id;

            if ($sisterId !== null) {
                $sister = Transaction::find($sisterId);
                if ($sister !== null) {
                    $transaction->setRelation('pendingSisterTransaction', $sister);
                }
            }
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
            'don_transaction_id' => null,
            'validee_at' => null,
        ]);

        // Soft-delete the sister transaction if it still exists (abandon de créance pair).
        if ($transaction->relationLoaded('pendingSisterTransaction')) {
            /** @var Transaction $sister */
            $sister = $transaction->getRelation('pendingSisterTransaction');
            $sister->delete();
        }

        Log::info('comptabilite.ndf.reverted_to_submitted', [
            'ndf_id' => $ndf->id,
            'transaction_id' => $transaction->id,
        ]);
    }
}
