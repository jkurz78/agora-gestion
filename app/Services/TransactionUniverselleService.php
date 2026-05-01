<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UsageComptable;
use App\Models\CompteBancaire;
use App\Tenant\TenantContext;
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
        ?string $statutReglement,
        ?string $sousCategorieFilter = null,
        bool $computeSolde = false,
        string $sortColumn = 'date',
        string $sortDirection = 'desc',
        int $perPage = 25,
        int $page = 1,
        bool $ndfUniquement = false,
    ): array {
        $allowedColumns = ['id', 'date', 'numero_piece', 'reference', 'tiers', 'libelle',
            'categorie_label', 'nb_lignes', 'compte_id', 'compte_nom', 'mode_paiement',
            'montant', 'pointe', 'source_type', 'tiers_type', 'tiers_id'];
        if (! in_array($sortColumn, $allowedColumns, true)) {
            $sortColumn = 'date';
        }
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $union = $this->buildUnion($compteId, $tiersId, $types, $dateDebut, $dateFin, $sousCategorieFilter, $ndfUniquement);

        $outer = DB::query()->fromSub($union, 't')
            ->when($searchTiers, fn ($q) => $q->where('t.tiers', 'like', "%{$searchTiers}%"))
            ->when($searchLibelle, fn ($q) => $q->where('t.libelle', 'like', "%{$searchLibelle}%"))
            ->when($searchReference, fn ($q) => $q->where('t.reference', 'like', "%{$searchReference}%"))
            ->when($searchNumeroPiece, fn ($q) => $q->where('t.numero_piece', 'like', "%{$searchNumeroPiece}%"))
            ->when($modePaiement, fn ($q) => $q->where('t.mode_paiement', $modePaiement))
            ->when($statutReglement, fn ($q) => $q->where('t.statut_reglement', $statutReglement))
            // Exclure les recettes à montant négatif ou nul du filtre Créances à recevoir.
            // Une recette à montant_total <= 0 est soit une extourne (Slice 1), soit invalide
            // comme créance à encaisser. Le sens dépense est laissé tel quel.
            // Préparation Slice 1 — voir docs/audit/2026-04-30-signe-negatif.md §3
            ->when(
                $statutReglement === 'en_attente',
                fn ($q) => $q->whereNot(fn ($w) => $w->where('t.source_type', 'recette')->where('t.montant', '<=', 0))
            )
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
                    $unionForSolde = $this->buildUnion($compteId, $tiersId, $types, $dateDebut, $dateFin, $sousCategorieFilter, $ndfUniquement);
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
        bool $ndfUniquement = false,
    ): Builder {
        // Map external string filter to UsageComptable (preserves external API, eliminates column interpolation)
        $flagToUsage = [
            'pour_dons' => UsageComptable::Don,
            'pour_cotisations' => UsageComptable::Cotisation,
            'pour_inscriptions' => UsageComptable::Inscription,
        ];
        $usageFilter = $flagToUsage[$sousCategorieFilter] ?? null;

        $include = [
            'depense' => $types === null || in_array('depense', $types, true),
            'recette' => $types === null || in_array('recette', $types, true),
            'virement' => $types === null || in_array('virement', $types, true),
        ];

        $queries = [];
        if ($include['depense']) {
            $queries[] = $this->brancheDepense($compteId, $tiersId, $dateDebut, $dateFin, $usageFilter, $ndfUniquement);
        }
        if ($include['recette']) {
            $queries[] = $this->brancheRecette($compteId, $tiersId, $dateDebut, $dateFin, $usageFilter, $ndfUniquement);
        }
        if ($include['virement'] && $usageFilter === null && ! $ndfUniquement) {
            $queries[] = $this->brancheVirementSortant($compteId, $tiersId, $dateDebut, $dateFin);
            $queries[] = $this->brancheVirementEntrant($compteId, $tiersId, $dateDebut, $dateFin);
        }

        if (empty($queries)) {
            // No types selected — return a query that yields no rows
            return DB::table('transactions')->whereRaw('1 = 0')->selectRaw(
                "id, 'depense' as source_type, NULL as date, NULL as numero_piece, NULL as reference,
                 NULL as tiers, NULL as tiers_type, NULL as tiers_id, NULL as libelle,
                 NULL as categorie_label, 0 as nb_lignes, NULL as compte_id, NULL as compte_nom,
                 NULL as mode_paiement, 0 as montant, NULL as pointe,
                 NULL as statut_reglement, NULL as remise_id, NULL as rapprochement_id,
                 NULL as notes, NULL as piece_jointe_path, NULL as piece_jointe_nom, 0 as is_helloasso,
                 NULL as extournee_at, 0 as is_extourne_miroir"
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
        ?UsageComptable $usageFilter = null,
        bool $ndfUniquement = false,
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
                (tx.statut_reglement = 'pointe') as pointe,
                tx.statut_reglement,
                tx.remise_id,
                tx.rapprochement_id,
                tx.notes,
                tx.piece_jointe_path,
                tx.piece_jointe_nom,
                (tx.helloasso_order_id IS NOT NULL) as is_helloasso,
                tx.extournee_at,
                EXISTS(SELECT 1 FROM extournes e WHERE e.transaction_extourne_id = tx.id AND e.deleted_at IS NULL) as is_extourne_miroir
            ")
            ->where('tx.type', 'depense')
            ->whereNull('tx.deleted_at')
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('tx.association_id', TenantContext::currentId()))
            ->when($compteId !== null, fn ($q) => $q->where('tx.compte_id', $compteId))
            ->when($tiersId !== null, fn ($q) => $q->where('tx.tiers_id', $tiersId))
            ->when($dateDebut, fn ($q) => $q->where('tx.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('tx.date', '<=', $dateFin))
            ->when($usageFilter !== null, fn ($q) => $q->whereExists(function ($sub) use ($usageFilter) {
                $sub->select(DB::raw(1))
                    ->from('transaction_lignes as tl_filter')
                    ->join('usages_sous_categories as usc_filter', 'usc_filter.sous_categorie_id', '=', 'tl_filter.sous_categorie_id')
                    ->whereColumn('tl_filter.transaction_id', 'tx.id')
                    ->where('usc_filter.usage', $usageFilter->value);
            }))
            ->when($ndfUniquement, fn ($q) => $q->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('notes_de_frais as ndf')
                    ->whereColumn('ndf.transaction_id', 'tx.id')
                    ->whereNull('ndf.deleted_at');
            }));
    }

    private function brancheRecette(
        ?int $compteId,
        ?int $tiersId,
        ?string $dateDebut,
        ?string $dateFin,
        ?UsageComptable $usageFilter = null,
        bool $ndfUniquement = false,
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
                (tx.statut_reglement = 'pointe') as pointe,
                tx.statut_reglement,
                tx.remise_id,
                tx.rapprochement_id,
                tx.notes,
                tx.piece_jointe_path,
                tx.piece_jointe_nom,
                (tx.helloasso_order_id IS NOT NULL) as is_helloasso,
                tx.extournee_at,
                EXISTS(SELECT 1 FROM extournes e WHERE e.transaction_extourne_id = tx.id AND e.deleted_at IS NULL) as is_extourne_miroir
            ")
            ->where('tx.type', 'recette')
            ->whereNull('tx.deleted_at')
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('tx.association_id', TenantContext::currentId()))
            ->when($compteId !== null, fn ($q) => $q->where('tx.compte_id', $compteId))
            ->when($tiersId !== null, fn ($q) => $q->where('tx.tiers_id', $tiersId))
            ->when($dateDebut, fn ($q) => $q->where('tx.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('tx.date', '<=', $dateFin))
            ->when($usageFilter !== null, fn ($q) => $q->whereExists(function ($sub) use ($usageFilter) {
                $sub->select(DB::raw(1))
                    ->from('transaction_lignes as tl_filter')
                    ->join('usages_sous_categories as usc_filter', 'usc_filter.sous_categorie_id', '=', 'tl_filter.sous_categorie_id')
                    ->whereColumn('tl_filter.transaction_id', 'tx.id')
                    ->where('usc_filter.usage', $usageFilter->value);
            }))
            ->when($ndfUniquement, fn ($q) => $q->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('notes_de_frais as ndf')
                    ->whereColumn('ndf.transaction_id', 'tx.id')
                    ->whereNull('ndf.deleted_at');
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
                NULL as statut_reglement,
                NULL as remise_id,
                NULL as rapprochement_id,
                vi.notes,
                NULL as piece_jointe_path,
                NULL as piece_jointe_nom,
                0 as is_helloasso,
                NULL as extournee_at,
                0 as is_extourne_miroir
            ")
            ->whereNull('vi.deleted_at')
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('vi.association_id', TenantContext::currentId()))
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
                NULL as statut_reglement,
                NULL as remise_id,
                NULL as rapprochement_id,
                vi.notes,
                NULL as piece_jointe_path,
                NULL as piece_jointe_nom,
                0 as is_helloasso,
                NULL as extournee_at,
                0 as is_extourne_miroir
            ")
            ->whereNull('vi.deleted_at')
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('vi.association_id', TenantContext::currentId()))
            ->when($tiersId !== null, fn ($q) => $q->whereRaw('1 = 0'))
            ->when($compteId !== null, fn ($q) => $q->where('vi.compte_destination_id', $compteId))
            ->when($dateDebut, fn ($q) => $q->where('vi.date', '>=', $dateDebut))
            ->when($dateFin, fn ($q) => $q->where('vi.date', '<=', $dateFin));
    }
}
