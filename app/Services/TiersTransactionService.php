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

        $recettes = DB::table('recettes as r')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'r.compte_id')
            ->selectRaw("r.id, 'recette' as source_type, r.date, r.libelle, cb.nom as compte, r.montant_total as montant")
            ->where('r.tiers_id', $id)
            ->whereNull('r.deleted_at');

        $depenses = DB::table('depenses as d')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'd.compte_id')
            ->selectRaw("d.id, 'depense' as source_type, d.date, d.libelle, cb.nom as compte, d.montant_total as montant")
            ->where('d.tiers_id', $id)
            ->whereNull('d.deleted_at');

        $dons = DB::table('dons as dn')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'dn.compte_id')
            ->selectRaw("dn.id, 'don' as source_type, dn.date, dn.objet as libelle, cb.nom as compte, dn.montant")
            ->where('dn.tiers_id', $id)
            ->whereNull('dn.deleted_at');

        $cotisations = DB::table('cotisations as c')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'c.compte_id')
            ->selectRaw("c.id, 'cotisation' as source_type, c.date_paiement as date, CONCAT('Cotisation ', c.exercice) as libelle, cb.nom as compte, c.montant")
            ->where('c.tiers_id', $id)
            ->whereNull('c.deleted_at');

        $union = $recettes
            ->unionAll($depenses)
            ->unionAll($dons)
            ->unionAll($cotisations);

        $allowed = ['date', 'source_type', 'montant'];
        $sortBy  = in_array($sortBy, $allowed, true) ? $sortBy : 'date';
        $sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        $query = DB::query()->fromSub($union, 't');

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
            $query->where('libelle', 'like', '%' . $search . '%');
        }

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }
}
