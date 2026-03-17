<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TransactionCompteService
{
    /**
     * @return array{paginator: LengthAwarePaginator, soldeAvantPage: float|null, showSolde: bool}
     */
    public function paginate(
        CompteBancaire $compte,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $searchTiers,
        string $sortColumn,
        string $sortDirection,
        int $perPage = 15,
        int $page = 1,
    ): array {
        $showSolde = empty($searchTiers) && $sortColumn === 'date' && $sortDirection === 'asc';

        $union = $this->buildUnion($compte, $dateDebut, $dateFin, $searchTiers);

        $outer = DB::query()->fromSub($union, 't')
            ->orderBy($sortColumn, $sortDirection);

        if ($sortColumn === 'date') {
            $outer->orderBy('source_type')->orderBy('id');
        }

        $paginator = $outer->paginate($perPage, ['*'], 'page', $page);

        $soldeAvantPage = null;
        if ($showSolde) {
            $offset = ($paginator->currentPage() - 1) * $perPage;
            $sumAvant = 0.0;
            if ($offset > 0) {
                $unionForSolde = $this->buildUnion($compte, $dateDebut, $dateFin, null);
                $inner = DB::query()->fromSub($unionForSolde, 'u')
                    ->select('montant')
                    ->orderBy('date')->orderBy('source_type')->orderBy('id')
                    ->limit($offset);
                $sumAvant = (float) DB::query()->fromSub($inner, 'avant')->sum('montant');
            }
            $soldeAvantPage = (float) $compte->solde_initial + $sumAvant;
        }

        return [
            'paginator' => $paginator,
            'soldeAvantPage' => $soldeAvantPage,
            'showSolde' => $showSolde,
        ];
    }

    private function buildUnion(
        CompteBancaire $compte,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $searchTiers,
    ): Builder {
        $id = $compte->id;
        $tiersLike = $searchTiers ? "%{$searchTiers}%" : null;

        $recettes = DB::table('recettes as r')
            ->leftJoin('tiers as tr', 'tr.id', '=', 'r.tiers_id')
            ->selectRaw("r.id, 'recette' as source_type, r.date, 'Recette' as type_label, TRIM(CONCAT(COALESCE(`tr`.prenom,''), ' ', COALESCE(`tr`.nom,''))) as tiers, `tr`.type as tiers_type, r.libelle, r.reference, r.montant_total as montant, r.mode_paiement, r.pointe, r.numero_piece")
            ->where('r.compte_id', $id)
            ->whereNull('r.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('r.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('r.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->whereRaw("TRIM(CONCAT(COALESCE(`tr`.prenom,''), ' ', COALESCE(`tr`.nom,''))) LIKE ?", [$tiersLike]));

        $depenses = DB::table('depenses as d')
            ->leftJoin('tiers as td', 'td.id', '=', 'd.tiers_id')
            ->selectRaw("d.id, 'depense' as source_type, d.date, 'Dépense' as type_label, TRIM(CONCAT(COALESCE(`td`.prenom,''), ' ', COALESCE(`td`.nom,''))) as tiers, `td`.type as tiers_type, d.libelle, d.reference, -(d.montant_total) as montant, d.mode_paiement, d.pointe, d.numero_piece")
            ->where('d.compte_id', $id)
            ->whereNull('d.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('d.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('d.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->whereRaw("TRIM(CONCAT(COALESCE(`td`.prenom,''), ' ', COALESCE(`td`.nom,''))) LIKE ?", [$tiersLike]));

        $dons = DB::table('dons as dn')
            ->leftJoin('tiers as do', 'do.id', '=', 'dn.tiers_id')
            ->selectRaw("dn.id, 'don' as source_type, dn.date, 'Don' as type_label, TRIM(CONCAT(COALESCE(`do`.prenom,''), ' ', COALESCE(`do`.nom,''))) as tiers, `do`.type as tiers_type, dn.objet as libelle, NULL as reference, dn.montant, dn.mode_paiement, dn.pointe, dn.numero_piece")
            ->where('dn.compte_id', $id)
            ->whereNull('dn.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('dn.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('dn.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->whereRaw("TRIM(CONCAT(COALESCE(`do`.prenom,''), ' ', COALESCE(`do`.nom,''))) LIKE ?", [$tiersLike]));

        $cotisations = DB::table('cotisations as c')
            ->leftJoin('tiers as t', 't.id', '=', 'c.tiers_id')
            ->selectRaw("c.id, 'cotisation' as source_type, c.date_paiement as date, 'Cotisation' as type_label, TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers, t.type as tiers_type, CONCAT('Cotisation ', c.exercice) as libelle, NULL as reference, c.montant, c.mode_paiement, c.pointe, c.numero_piece")
            ->where('c.compte_id', $id)
            ->whereNull('c.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('c.date_paiement', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('c.date_paiement', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->whereRaw("TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) LIKE ?", [$tiersLike]));

        $virementsSource = DB::table('virements_internes as vi')
            ->join('comptes_bancaires as cb', 'cb.id', '=', 'vi.compte_destination_id')
            ->selectRaw("vi.id, 'virement_sortant' as source_type, vi.date, 'Virement sortant' as type_label, cb.nom as tiers, NULL as tiers_type, CONCAT('Virement vers ', cb.nom) as libelle, vi.reference, -(vi.montant) as montant, NULL as mode_paiement, (vi.rapprochement_source_id IS NOT NULL) as pointe, vi.numero_piece")
            ->where('vi.compte_source_id', $id)
            ->whereNull('vi.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('vi.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('vi.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->where('cb.nom', 'like', $tiersLike));

        $virementsDestination = DB::table('virements_internes as vi')
            ->join('comptes_bancaires as cb', 'cb.id', '=', 'vi.compte_source_id')
            ->selectRaw("vi.id, 'virement_entrant' as source_type, vi.date, 'Virement entrant' as type_label, cb.nom as tiers, NULL as tiers_type, CONCAT('Virement depuis ', cb.nom) as libelle, vi.reference, vi.montant, NULL as mode_paiement, (vi.rapprochement_destination_id IS NOT NULL) as pointe, vi.numero_piece")
            ->where('vi.compte_destination_id', $id)
            ->whereNull('vi.deleted_at')
            ->when($dateDebut, fn (Builder $q) => $q->where('vi.date', '>=', $dateDebut))
            ->when($dateFin, fn (Builder $q) => $q->where('vi.date', '<=', $dateFin))
            ->when($tiersLike, fn (Builder $q) => $q->where('cb.nom', 'like', $tiersLike));

        return $recettes
            ->unionAll($depenses)
            ->unionAll($dons)
            ->unionAll($cotisations)
            ->unionAll($virementsSource)
            ->unionAll($virementsDestination);
    }
}
