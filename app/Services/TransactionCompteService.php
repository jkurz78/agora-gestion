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

        $transactions = DB::table('transactions as tx')
            ->leftJoin('tiers as t', 't.id', '=', 'tx.tiers_id')
            ->selectRaw("
                tx.id,
                tx.type as source_type,
                tx.date,
                CASE WHEN tx.type = 'depense' THEN 'Dépense' ELSE 'Recette' END as type_label,
                TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers,
                t.type as tiers_type,
                tx.libelle,
                tx.reference,
                CASE WHEN tx.type = 'depense' THEN -(tx.montant_total) ELSE tx.montant_total END as montant,
                tx.mode_paiement,
                tx.pointe,
                tx.numero_piece
            ")
            ->where('tx.compte_id', $id)
            ->whereNull('tx.deleted_at')
            ->when($dateDebut, fn ($q) => $q->where('tx.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('tx.date', '<=', $dateFin))
            ->when($tiersLike, fn ($q) => $q->whereRaw(
                "TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) LIKE ?", [$tiersLike]
            ));

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

        return $transactions
            ->unionAll($virementsSource)
            ->unionAll($virementsDestination);
    }
}
