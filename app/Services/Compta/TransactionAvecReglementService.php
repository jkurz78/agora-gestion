<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Models\Transaction;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TransactionAvecReglementService
{
    public function __construct(
        private readonly TransactionService $transactionService,
        private readonly PostesTiersOuvertsService $postesTiersOuverts,
        private readonly PosteTiersReglementService $posteTiersReglement,
    ) {}

    /**
     * Enregistre la T1 puis, si demandé, son règlement T2 dans la même transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lignes
     */
    public function enregistrer(
        ?Transaction $transaction,
        array $data,
        array $lignes,
        ?CarbonImmutable $dateReglement,
        ?ModePaiement $mode,
        ?int $compteBancaireId,
        int $exercice,
    ): Transaction {
        return DB::transaction(function () use (
            $transaction,
            $data,
            $lignes,
            $dateReglement,
            $mode,
            $compteBancaireId,
            $exercice,
        ): Transaction {
            $t1 = $transaction === null
                ? $this->transactionService->create($data, $lignes)
                : $this->transactionService->update($transaction, $data, $lignes);

            if ($dateReglement === null || $mode === null) {
                return $t1->fresh();
            }

            $poste = $this->postesTiersOuverts->pourTransaction($t1, $exercice);
            if ($poste === null) {
                throw new RuntimeException('Aucun poste tiers ouvert ne permet de créer ce règlement.');
            }

            $this->posteTiersReglement->regler(new PosteTiersReglementData(
                ligneId: $poste->ligneActionId,
                montantCentimes: $poste->soldeCentimes,
                date: $dateReglement,
                mode: $mode,
                compteBancaireId: $compteBancaireId,
                exercice: $exercice,
            ));

            return $t1->fresh();
        });
    }
}
