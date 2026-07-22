<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\TransactionLigne;
use App\Models\VirementInterne;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class SoldeService
{
    /**
     * Compute the current real balance of a bank account.
     *
     * Starts from solde_initial (at date_solde_initial) and adds all inflows
     * (recettes, virements received) and subtracts all outflows
     * (depenses, virements sent) since that date. Soft-deleted records
     * are automatically excluded via Eloquent global scopes.
     */
    public function solde(
        CompteBancaire $compte,
        ?int $exercice = null,
        ?CarbonInterface $date = null,
    ): float {
        $exerciceService = app(ExerciceService::class);
        $exerciceEffectif = $exercice ?? $exerciceService->current();

        if (ANouveauGeneration::activePourCible($exerciceEffectif) !== null) {
            $comptePcg = Compte::query()
                ->where('compte_bancaire_id', $compte->id)
                ->bancaires()
                ->first();

            if ($comptePcg !== null) {
                $range = $exerciceService->dateRange($exerciceEffectif);
                $dateFin = $date !== null
                    ? CarbonImmutable::instance($date)
                    : ($exerciceEffectif === $exerciceService->current()
                        ? CarbonImmutable::today()
                        : $range['end']);

                if ($dateFin->gt($range['end'])) {
                    $dateFin = $range['end'];
                }

                $net = TransactionLigne::query()
                    ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
                    ->where('transaction_lignes.compte_id', $comptePcg->id)
                    ->whereNull('transactions.deleted_at')
                    ->whereDate('transactions.date', '>=', $range['start']->toDateString())
                    ->whereDate('transactions.date', '<=', $dateFin->toDateString())
                    ->selectRaw('COALESCE(SUM(transaction_lignes.debit), 0) - COALESCE(SUM(transaction_lignes.credit), 0) AS net')
                    ->value('net');

                return round((float) $net, 2);
            }
        }

        $dateRef = $compte->date_solde_initial?->toDateString() ?? '1900-01-01';
        $dateFin = $date?->toDateString();

        $entrees =
            (float) $compte->recettes()
                ->where('date', '>=', $dateRef)
                ->when($dateFin, fn ($query) => $query->where('date', '<=', $dateFin))
                ->sum('montant_total')
            + (float) VirementInterne::where('compte_destination_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->when($dateFin, fn ($query) => $query->where('date', '<=', $dateFin))
                ->sum('montant');

        $sorties =
            (float) $compte->depenses()
                ->where('date', '>=', $dateRef)
                ->when($dateFin, fn ($query) => $query->where('date', '<=', $dateFin))
                ->sum('montant_total')
            + (float) VirementInterne::where('compte_source_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->when($dateFin, fn ($query) => $query->where('date', '<=', $dateFin))
                ->sum('montant');

        return round((float) $compte->solde_initial + $entrees - $sorties, 2);
    }
}
