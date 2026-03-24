<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TransactionUniverselleService
{
    /**
     * @param  array<string>|null  $types  null=all; subset of ['depense','recette','virement']
     * @return array{paginator: LengthAwarePaginator, soldeAvantPage: float|null}
     */
    public function paginate(
        ?int $compteId,
        ?int $tiersId,
        ?array $types,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $searchTiers,
        ?string $searchLibelle,
        ?string $searchReference,
        ?string $searchNumeroPiece,
        ?string $modePaiement,
        ?bool $pointe,
        ?string $sousCategorieFilter = null,
        bool $computeSolde = false,
        string $sortColumn = 'date',
        string $sortDirection = 'desc',
        int $perPage = 25,
        int $page = 1,
    ): array {
        $allowedColumns = ['id', 'date', 'numero_piece', 'reference', 'tiers', 'libelle',
            'categorie_label', 'nb_lignes', 'compte_id', 'compte_nom', 'mode_paiement',
            'montant', 'pointe', 'source_type', 'tiers_type', 'tiers_id'];
        if (! in_array($sortColumn, $allowedColumns, true)) {
            $sortColumn = 'date';
        }
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $union = $this->buildUnion($compteId, $tiersId, $types, $dateDebut, $dateFin, $sousCategorieFilter);

        $outer = DB::query()->fromSub($union, 't')
            ->when($searchTiers, fn ($q) => $q->where('t.tiers', 'like', "%{$searchTiers}%"))
            ->when($searchLibelle, fn ($q) => $q->where('t.libelle', 'like', "%{$searchLibelle}%"))
            ->when($searchReference, fn ($q) => $q->where('t.reference', 'like', "%{$searchReference}%"))
            ->when($searchNumeroPiece, fn ($q) => $q->where('t.numero_piece', 'like', "%{$searchNumeroPiece}%"))
            ->when($modePaiement, fn ($q) => $q->where('t.mode_paiement', $modePaiement))
            ->when($pointe !== null, fn ($q) => $q->where('t.pointe', $pointe))
            ->orderBy("t.{$sortColumn}", $sortDirection)
            ->orderBy('t.source_type')
            ->orderBy('t.id');

        $paginator = $outer->paginate($perPage, ['*'], 'page', $page);

        $soldeAvantPage = null;
        if ($computeSolde && $compteId !== null) {
            $compte = CompteBancaire::find($compteId);
            if ($compte !== null) {
                $offset = ($paginator->currentPage() - 1) * $perPage;
                $sumAvant = 0.0;
                if ($offset > 0) {
                    $unionForSolde = $this->buildUnion($compteId, $tiersId, $types, $dateDebut, $dateFin, $sousCategorieFilter);
                    $inner = DB::query()->fromSub($unionForSolde, 'u')
                        ->select('montant')
                        ->orderBy("u.{$sortColumn}", $sortDirection)
                        ->orderBy('u.source_type')
                        ->orderBy('u.id')
                        ->limit($offset);
                    $sumAvant = (float) DB::query()->fromSub($inner, 'avant')->sum('montant');
                }
                $soldeAvantPage = (float) $compte->solde_initial + $sumAvant;
            }
        }

        return [
            'paginator' => $paginator,
            'soldeAvantPage' => $soldeAvantPage,
        ];
    }

    /**
     * @param  array<string>|null  $types
     */
    private function buildUnion(
        ?int $compteId,
        ?int $tiersId,
        ?array $types,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $sousCategorieFilter = null,
    ): Builder {
        // Whitelist sous-catégorie filter to prevent SQL injection (column name interpolation)
        $allowedFilters = ['pour_dons', 'pour_cotisations', 'pour_inscriptions'];
        if ($sousCategorieFilter !== null && ! in_array($sousCategorieFilter, $allowedFilters, true)) {
            $sousCategorieFilter = null;
        }

        $include = [
            'depense' => $types === null || in_array('depense', $types, true),
            'recette' => $types === null || in_array('recette', $types, true),
            'virement' => $types === null || in_array('virement', $types, true),
        ];

        $queries = [];
        if ($include['depense']) {
            $queries[] = $this->brancheDepense($compteId, $tiersId, $dateDebut, $dateFin, $sousCategorieFilter);
        }
        if ($include['recette']) {
            $queries[] = $this->brancheRecette($compteId, $tiersId, $dateDebut, $dateFin, $sousCategorieFilter);
        }
        if ($include['virement'] && $sousCategorieFilter === null) {
            $queries[] = $this->brancheVirementSortant($compteId, $tiersId, $dateDebut, $dateFin);
            $queries[] = $this->brancheVirementEntrant($compteId, $tiersId, $dateDebut, $dateFin);
        }

        if (empty($queries)) {
            // No types selected — return a query that yields no rows
            return DB::table('transactions')->whereRaw('1 = 0')->selectRaw(
                "id, 'depense' as source_type, NULL as date, NULL as numero_piece, NULL as reference,
                 NULL as tiers, NULL as tiers_type, NULL as tiers_id, NULL as libelle,
                 NULL as categorie_label, 0 as nb_lignes, NULL as compte_id, NULL as compte_nom,
                 NULL as mode_paiement, 0 as montant, NULL as pointe, 0 as is_helloasso"
            );
        }

        $base = array_shift($queries);
        foreach ($queries as $q) {
            $base->unionAll($q);
        }

        return $base;
    }

    private function brancheDepense(
        ?int $compteId,
        ?int $tiersId,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $sousCategorieFilter = null,
    ): Builder {
        return DB::table('transactions as tx')
            ->leftJoin('tiers as t', 't.id', '=', 'tx.tiers_id')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'tx.compte_id')
            ->selectRaw("
                tx.id,
                'depense' as source_type,
                DATE(tx.date) as date,
                tx.numero_piece,
                tx.reference,
                TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers,
                t.type as tiers_type,
                tx.tiers_id,
                tx.libelle,
                (SELECT sc.nom FROM transaction_lignes tl
                 JOIN sous_categories sc ON sc.id = tl.sous_categorie_id
                 WHERE tl.transaction_id = tx.id ORDER BY tl.id LIMIT 1) as categorie_label,
                (SELECT COUNT(*) FROM transaction_lignes WHERE transaction_id = tx.id) as nb_lignes,
                tx.compte_id,
                cb.nom as compte_nom,
                tx.mode_paiement,
                -(tx.montant_total) as montant,
                tx.pointe,
                tx.notes,
                (tx.helloasso_order_id IS NOT NULL) as is_helloasso
            ")
            ->where('tx.type', 'depense')
            ->whereNull('tx.deleted_at')
            ->when($compteId !== null, fn ($q) => $q->where('tx.compte_id', $compteId))
            ->when($tiersId !== null, fn ($q) => $q->where('tx.tiers_id', $tiersId))
            ->when($dateDebut, fn ($q) => $q->where('tx.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('tx.date', '<=', $dateFin))
            ->when($sousCategorieFilter, fn ($q) => $q->whereExists(function ($sub) use ($sousCategorieFilter) {
                $sub->select(DB::raw(1))
                    ->from('transaction_lignes as tl_filter')
                    ->join('sous_categories as sc_filter', 'sc_filter.id', '=', 'tl_filter.sous_categorie_id')
                    ->whereColumn('tl_filter.transaction_id', 'tx.id')
                    ->where("sc_filter.{$sousCategorieFilter}", true);
            }));
    }

    private function brancheRecette(
        ?int $compteId,
        ?int $tiersId,
        ?string $dateDebut,
        ?string $dateFin,
        ?string $sousCategorieFilter = null,
    ): Builder {
        return DB::table('transactions as tx')
            ->leftJoin('tiers as t', 't.id', '=', 'tx.tiers_id')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'tx.compte_id')
            ->selectRaw("
                tx.id,
                'recette' as source_type,
                DATE(tx.date) as date,
                tx.numero_piece,
                tx.reference,
                TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers,
                t.type as tiers_type,
                tx.tiers_id,
                tx.libelle,
                (SELECT sc.nom FROM transaction_lignes tl
                 JOIN sous_categories sc ON sc.id = tl.sous_categorie_id
                 WHERE tl.transaction_id = tx.id ORDER BY tl.id LIMIT 1) as categorie_label,
                (SELECT COUNT(*) FROM transaction_lignes WHERE transaction_id = tx.id) as nb_lignes,
                tx.compte_id,
                cb.nom as compte_nom,
                tx.mode_paiement,
                tx.montant_total as montant,
                tx.pointe,
                tx.notes,
                (tx.helloasso_order_id IS NOT NULL) as is_helloasso
            ")
            ->where('tx.type', 'recette')
            ->whereNull('tx.deleted_at')
            ->when($compteId !== null, fn ($q) => $q->where('tx.compte_id', $compteId))
            ->when($tiersId !== null, fn ($q) => $q->where('tx.tiers_id', $tiersId))
            ->when($dateDebut, fn ($q) => $q->where('tx.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('tx.date', '<=', $dateFin))
            ->when($sousCategorieFilter, fn ($q) => $q->whereExists(function ($sub) use ($sousCategorieFilter) {
                $sub->select(DB::raw(1))
                    ->from('transaction_lignes as tl_filter')
                    ->join('sous_categories as sc_filter', 'sc_filter.id', '=', 'tl_filter.sous_categorie_id')
                    ->whereColumn('tl_filter.transaction_id', 'tx.id')
                    ->where("sc_filter.{$sousCategorieFilter}", true);
            }));
    }

    private function brancheVirementSortant(
        ?int $compteId,
        ?int $tiersId,
        ?string $dateDebut,
        ?string $dateFin,
    ): Builder {
        return DB::table('virements_internes as vi')
            ->join('comptes_bancaires as cb_dest', 'cb_dest.id', '=', 'vi.compte_destination_id')
            ->join('comptes_bancaires as cb_src', 'cb_src.id', '=', 'vi.compte_source_id')
            ->selectRaw("
                vi.id,
                'virement_sortant' as source_type,
                DATE(vi.date) as date,
                vi.numero_piece,
                vi.reference,
                CONCAT('→ ', cb_dest.nom) as tiers,
                NULL as tiers_type,
                NULL as tiers_id,
                CONCAT('Virement vers ', cb_dest.nom) as libelle,
                NULL as categorie_label,
                1 as nb_lignes,
                vi.compte_source_id as compte_id,
                cb_src.nom as compte_nom,
                NULL as mode_paiement,
                -(vi.montant) as montant,
                (vi.rapprochement_source_id IS NOT NULL) as pointe,
                vi.notes,
                0 as is_helloasso
            ")
            ->whereNull('vi.deleted_at')
            ->when($tiersId !== null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($compteId !== null, fn ($q) => $q->where('vi.compte_source_id', $compteId))
            ->when($dateDebut, fn ($q) => $q->where('vi.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('vi.date', '<=', $dateFin));
    }

    private function brancheVirementEntrant(
        ?int $compteId,
        ?int $tiersId,
        ?string $dateDebut,
        ?string $dateFin,
    ): Builder {
        return DB::table('virements_internes as vi')
            ->join('comptes_bancaires as cb_src', 'cb_src.id', '=', 'vi.compte_source_id')
            ->join('comptes_bancaires as cb_dest', 'cb_dest.id', '=', 'vi.compte_destination_id')
            ->selectRaw("
                vi.id,
                'virement_entrant' as source_type,
                DATE(vi.date) as date,
                vi.numero_piece,
                vi.reference,
                CONCAT('← ', cb_src.nom) as tiers,
                NULL as tiers_type,
                NULL as tiers_id,
                CONCAT('Virement depuis ', cb_src.nom) as libelle,
                NULL as categorie_label,
                1 as nb_lignes,
                vi.compte_destination_id as compte_id,
                cb_dest.nom as compte_nom,
                NULL as mode_paiement,
                vi.montant,
                (vi.rapprochement_destination_id IS NOT NULL) as pointe,
                vi.notes,
                0 as is_helloasso
            ")
            ->whereNull('vi.deleted_at')
            ->when($tiersId !== null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($compteId !== null, fn ($q) => $q->where('vi.compte_destination_id', $compteId))
            ->when($dateDebut, fn ($q) => $q->where('vi.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('vi.date', '<=', $dateFin));
    }
}
