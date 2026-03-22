<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tiers;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class TiersTransactionService
{
    public function paginate(
        Tiers $tiers,
        string $typeFilter,
        string $dateDebut,
        string $dateFin,
        string $search,
        string $sortBy,
        string $sortDir,
        int $perPage = 50,
    ): LengthAwarePaginator {
        $id = $tiers->id;

        $transactions = DB::table('transactions as tx')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'tx.compte_id')
            ->selectRaw("tx.id, tx.type as source_type, tx.date, tx.libelle, cb.nom as compte, CASE WHEN tx.type = 'depense' THEN -(tx.montant_total) ELSE tx.montant_total END as montant")
            ->where('tx.tiers_id', $id)
            ->whereNull('tx.deleted_at');

        $allowed = ['date', 'source_type', 'montant'];
        $sortBy = in_array($sortBy, $allowed, true) ? $sortBy : 'date';
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $query = DB::query()->fromSub($transactions, 't');

        if ($typeFilter !== '') {
            $query->where('source_type', $typeFilter);
        }
        if ($dateDebut !== '') {
            $query->where('date', '>=', $dateDebut);
        }
        if ($dateFin !== '') {
            $query->where('date', '<=', $dateFin);
        }
        if ($search !== '') {
            $query->where('libelle', 'like', '%'.$search.'%');
        }

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }
}
