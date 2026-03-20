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
     * @param  array<string>|null  $types  null=all; subset of ['depense','recette','don','cotisation','virement']
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
        bool $computeSolde = false,
        string $sortColumn = 'date',
        string $sortDirection = 'desc',
        int $perPage = 25,
        int $page = 1,
    ): array {
        $union = $this->buildUnion($compteId, $tiersId, $types, $dateDebut, $dateFin);

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
                    $unionForSolde = $this->buildUnion($compteId, $tiersId, $types, $dateDebut, $dateFin);
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
    ): Builder {
        $include = [
            'depense' => $types === null || in_array('depense', $types, true),
            'recette' => $types === null || in_array('recette', $types, true),
            'don' => $types === null || in_array('don', $types, true),
            'cotisation' => $types === null || in_array('cotisation', $types, true),
            'virement' => $types === null || in_array('virement', $types, true),
        ];

        $queries = [];
        if ($include['depense']) {
            $queries[] = $this->brancheDepense($compteId, $tiersId, $dateDebut, $dateFin);
        }
        if ($include['recette']) {
            $queries[] = $this->brancheRecette($compteId, $tiersId, $dateDebut, $dateFin);
        }
        if ($include['don']) {
            $queries[] = $this->brancheDon($compteId, $tiersId, $dateDebut, $dateFin);
        }
        if ($include['cotisation']) {
            $queries[] = $this->brancheCotisation($compteId, $tiersId, $dateDebut, $dateFin);
        }
        if ($include['virement']) {
            $queries[] = $this->brancheVirementSortant($compteId, $tiersId, $dateDebut, $dateFin);
            $queries[] = $this->brancheVirementEntrant($compteId, $tiersId, $dateDebut, $dateFin);
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
                tx.pointe
            ")
            ->where('tx.type', 'depense')
            ->whereNull('tx.deleted_at')
            ->when($compteId !== null, fn ($q) => $q->where('tx.compte_id', $compteId))
            ->when($tiersId !== null, fn ($q) => $q->where('tx.tiers_id', $tiersId))
            ->when($dateDebut, fn ($q) => $q->where('tx.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('tx.date', '<=', $dateFin));
    }

    private function brancheRecette(
        ?int $compteId,
        ?int $tiersId,
        ?string $dateDebut,
        ?string $dateFin,
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
                tx.pointe
            ")
            ->where('tx.type', 'recette')
            ->whereNull('tx.deleted_at')
            ->when($compteId !== null, fn ($q) => $q->where('tx.compte_id', $compteId))
            ->when($tiersId !== null, fn ($q) => $q->where('tx.tiers_id', $tiersId))
            ->when($dateDebut, fn ($q) => $q->where('tx.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('tx.date', '<=', $dateFin));
    }

    private function brancheDon(
        ?int $compteId,
        ?int $tiersId,
        ?string $dateDebut,
        ?string $dateFin,
    ): Builder {
        return DB::table('dons as dn')
            ->leftJoin('tiers as do', 'do.id', '=', 'dn.tiers_id')
            ->leftJoin('sous_categories as sc', 'sc.id', '=', 'dn.sous_categorie_id')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'dn.compte_id')
            ->selectRaw("
                dn.id,
                'don' as source_type,
                DATE(dn.date) as date,
                dn.numero_piece,
                NULL as reference,
                TRIM(CONCAT(COALESCE(`do`.prenom,''), ' ', COALESCE(`do`.nom,''))) as tiers,
                `do`.type as tiers_type,
                dn.tiers_id,
                dn.objet as libelle,
                sc.nom as categorie_label,
                1 as nb_lignes,
                dn.compte_id,
                cb.nom as compte_nom,
                dn.mode_paiement,
                dn.montant,
                dn.pointe
            ")
            ->whereNull('dn.deleted_at')
            ->when($compteId !== null, fn ($q) => $q->where('dn.compte_id', $compteId))
            ->when($tiersId !== null, fn ($q) => $q->where('dn.tiers_id', $tiersId))
            ->when($dateDebut, fn ($q) => $q->where('dn.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('dn.date', '<=', $dateFin));
    }

    private function brancheCotisation(
        ?int $compteId,
        ?int $tiersId,
        ?string $dateDebut,
        ?string $dateFin,
    ): Builder {
        return DB::table('cotisations as c')
            ->leftJoin('tiers as t', 't.id', '=', 'c.tiers_id')
            ->leftJoin('sous_categories as sc', 'sc.id', '=', 'c.sous_categorie_id')
            ->leftJoin('comptes_bancaires as cb', 'cb.id', '=', 'c.compte_id')
            ->selectRaw("
                c.id,
                'cotisation' as source_type,
                DATE(c.date_paiement) as date,
                c.numero_piece,
                NULL as reference,
                TRIM(CONCAT(COALESCE(t.prenom,''), ' ', COALESCE(t.nom,''))) as tiers,
                t.type as tiers_type,
                c.tiers_id,
                CONCAT('Cotisation ', c.exercice) as libelle,
                sc.nom as categorie_label,
                1 as nb_lignes,
                c.compte_id,
                cb.nom as compte_nom,
                c.mode_paiement,
                c.montant,
                c.pointe
            ")
            ->whereNull('c.deleted_at')
            ->when($compteId !== null, fn ($q) => $q->where('c.compte_id', $compteId))
            ->when($tiersId !== null, fn ($q) => $q->where('c.tiers_id', $tiersId))
            ->when($dateDebut, fn ($q) => $q->where('c.date_paiement', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('c.date_paiement', '<=', $dateFin));
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
                (vi.rapprochement_source_id IS NOT NULL) as pointe
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
                (vi.rapprochement_destination_id IS NOT NULL) as pointe
            ")
            ->whereNull('vi.deleted_at')
            ->when($tiersId !== null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($compteId !== null, fn ($q) => $q->where('vi.compte_destination_id', $compteId))
            ->when($dateDebut, fn ($q) => $q->where('vi.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('vi.date', '<=', $dateFin));
    }
}
