<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

final class RemiseBancaireService
{
    public function creer(array $data): RemiseBancaire
    {
        return DB::transaction(function () use ($data): RemiseBancaire {
            $numero = (int) RemiseBancaire::withTrashed()->max('numero') + 1;
            $modePaiement = ModePaiement::from($data['mode_paiement']);
            $prefix = $modePaiement === ModePaiement::Cheque ? 'chèques' : 'espèces';

            return RemiseBancaire::create([
                'numero' => $numero,
                'date' => $data['date'],
                'mode_paiement' => $data['mode_paiement'],
                'compte_cible_id' => $data['compte_cible_id'],
                'libelle' => "Remise {$prefix} n°{$numero}",
                'saisi_par' => auth()->id(),
            ]);
        });
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function enregistrerBrouillon(RemiseBancaire $remise, array $transactionIds): void
    {
        DB::transaction(function () use ($remise, $transactionIds): void {
            // Retirer les transactions déselectionnées → repasser en attente
            Transaction::where('remise_id', $remise->id)
                ->whereNotIn('id', $transactionIds)
                ->update([
                    'remise_id' => null,
                    'statut_reglement' => StatutReglement::EnAttente->value,
                    'reference' => null,
                ]);

            // Ajouter les transactions sélectionnées → reçues (prêtes pour dépôt)
            if (! empty($transactionIds)) {
                Transaction::whereIn('id', $transactionIds)
                    ->update([
                        'remise_id' => $remise->id,
                        'statut_reglement' => StatutReglement::Recu->value,
                    ]);
            }
        });
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function comptabiliser(RemiseBancaire $remise, array $transactionIds): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        DB::transaction(function () use ($remise, $transactionIds): void {
            $prefix = $remise->referencePrefix();
            $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
            $index = 0;

            foreach ($transactionIds as $txId) {
                $tx = Transaction::findOrFail($txId);

                if ($tx->mode_paiement !== $remise->mode_paiement) {
                    throw new \RuntimeException(
                        "La transaction #{$txId} n'a pas le bon mode de paiement ({$tx->mode_paiement?->label()})."
                    );
                }

                $index++;
                $reference = sprintf(
                    '%s-%s-%s',
                    $prefix,
                    $numeroPadded,
                    str_pad((string) $index, 3, '0', STR_PAD_LEFT)
                );

                $tx->update([
                    'remise_id' => $remise->id,
                    'statut_reglement' => StatutReglement::Recu->value,
                    'reference' => $reference,
                ]);
            }
        });
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function modifier(RemiseBancaire $remise, array $transactionIds): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        if (empty($transactionIds)) {
            $this->supprimer($remise);

            return;
        }

        DB::transaction(function () use ($remise, $transactionIds): void {
            $aRetirer = Transaction::where('remise_id', $remise->id)
                ->whereNotIn('id', $transactionIds)
                ->get();

            foreach ($aRetirer as $tx) {
                if ($tx->factures()->where('statut', '!=', StatutFacture::Brouillon->value)->exists()) {
                    throw new \RuntimeException(
                        "Impossible de retirer la transaction #{$tx->id} : liée à une facture."
                    );
                }

                $tx->update([
                    'remise_id' => null,
                    'statut_reglement' => StatutReglement::EnAttente->value,
                    'reference' => null,
                ]);
            }

            $prefix = $remise->referencePrefix();
            $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
            $index = Transaction::where('remise_id', $remise->id)->count();

            foreach (Transaction::whereIn('id', $transactionIds)->whereNull('reference')->get() as $tx) {
                $index++;
                $tx->update([
                    'remise_id' => $remise->id,
                    'statut_reglement' => StatutReglement::Recu->value,
                    'reference' => sprintf(
                        '%s-%s-%s',
                        $prefix,
                        $numeroPadded,
                        str_pad((string) $index, 3, '0', STR_PAD_LEFT)
                    ),
                ]);
            }
        });
    }

    public function supprimer(RemiseBancaire $remise): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        if (Transaction::where('remise_id', $remise->id)
            ->whereHas('factures', fn ($q) => $q->where('statut', '!=', StatutFacture::Brouillon->value))
            ->exists()) {
            throw new \RuntimeException('Impossible de supprimer : des transactions sont liées à des factures.');
        }

        DB::transaction(function () use ($remise): void {
            Transaction::where('remise_id', $remise->id)->update([
                'remise_id' => null,
                'statut_reglement' => StatutReglement::EnAttente->value,
                'reference' => null,
            ]);
            $remise->delete();
        });
    }
}
