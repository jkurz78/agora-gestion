<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\Famille;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Source plate réutilisable des lignes de ventilation financière (« la spine »).
 *
 * Produit des lignes plates SIGNÉES (recette +, dépense −) et ÉCLATÉES par affectation
 * (transaction_ligne_affectations), réalisé uniquement. Alimente l'écran Analyse
 * (PivotTable) et l'export Excel « analyse-financier » (et, lots suivants, l'export
 * ventilé et l'Analyse par tiers).
 *
 * Le motif Q1 (lignes sans affectation) / Q2 (affectations) est repris en esprit de
 * {@see CompteResultatBuilder::fetchClasseRowsPD()} — lecture compte-first : JOIN direct
 * sur `transaction_lignes.compte_id`, `familles` résolue par préfixe à 2 chiffres du
 * `numero_pcg` — la ventilation est portée par `compte_id` seul.
 */
final class VentilationFinanciereService
{
    /** @return list<array<string, mixed>> */
    public function pourExercice(int $exercice): array
    {
        $range = app(ExerciceService::class)->dateRange($exercice);
        // dateRange() retourne des CarbonImmutable (non des string comme spécifié) — on caste
        $start = (string) $range['start']->toDateString();
        $end = (string) $range['end']->toDateString();

        $rows = array_merge(
            $this->q1($start, $end)->get()->all(),
            $this->q2($start, $end)->get()->all(),
        );

        return array_map(
            fn (object $row): array => $this->enrich((array) $row, $exercice),
            $rows,
        );
    }

    /**
     * Q1 — lignes SANS affectation, au grain ligne.
     * Les lignes possédant ≥ 1 affectation sont exclues via whereNull('tla.id')
     * (même mécanisme que le CR par opérations) pour éviter le double comptage avec Q2.
     */
    private function q1(string $start, string $end): Builder
    {
        $q = DB::table('transaction_lignes')
            ->join('transactions as tx', 'tx.id', '=', 'transaction_lignes.transaction_id')
            ->join('tiers', 'tiers.id', '=', 'tx.tiers_id')
            ->join('comptes as cpt', 'cpt.id', '=', 'transaction_lignes.compte_id')
            ->join('comptes_bancaires as cb', 'cb.id', '=', 'tx.compte_id')
            ->leftJoin('operations as op', 'op.id', '=', 'transaction_lignes.operation_id')
            ->leftJoin('type_operations as topo', 'topo.id', '=', 'op.type_operation_id')
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'transaction_lignes.id')
            ->whereNull('transaction_lignes.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereNull('tla.id')
            ->whereBetween('tx.date', [$start, $end])
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('tx.association_id', TenantContext::currentId()))
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('cpt.association_id', TenantContext::currentId()));
        $this->joinFamille($q);

        return $q->select($this->selectColumns('transaction_lignes.seance', 'transaction_lignes.montant'));
    }

    /**
     * Q2 — une ligne de sortie par affectation. Opération/séance/montant viennent de
     * l'affectation ; tiers, compte/famille et compte bancaire restent ceux de la ligne.
     */
    private function q2(string $start, string $end): Builder
    {
        $q = DB::table('transaction_ligne_affectations as tla')
            ->join('transaction_lignes as tl', 'tl.id', '=', 'tla.transaction_ligne_id')
            ->join('transactions as tx', 'tx.id', '=', 'tl.transaction_id')
            ->join('tiers', 'tiers.id', '=', 'tx.tiers_id')
            ->join('comptes as cpt', 'cpt.id', '=', 'tl.compte_id')
            ->join('comptes_bancaires as cb', 'cb.id', '=', 'tx.compte_id')
            ->leftJoin('operations as op', 'op.id', '=', 'tla.operation_id')
            ->leftJoin('type_operations as topo', 'topo.id', '=', 'op.type_operation_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereBetween('tx.date', [$start, $end])
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('tx.association_id', TenantContext::currentId()))
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('cpt.association_id', TenantContext::currentId()));
        $this->joinFamille($q);

        return $q->select($this->selectColumns('tla.seance', 'tla.montant'));
    }

    /**
     * Ajoute au query builder le LEFT JOIN vers `familles`, résolue par préfixe à
     * 2 chiffres du `numero_pcg` (cf. {@see Famille}).
     *
     * Suppose que l'alias `cpt` (comptes, joint directement sur `compte_id`) est déjà
     * présent sur la requête — lecture compte-first.
     */
    private function joinFamille(Builder $query): void
    {
        $query->leftJoin('familles as f', function ($join): void {
            $join->on('f.code', '=', DB::raw('SUBSTR(cpt.numero_pcg, 1, 2)'))
                ->on('f.association_id', '=', 'cpt.association_id');
        });
    }

    /**
     * Colonnes communes Q1/Q2 (alias identiques pour fusion homogène).
     * Les expressions séance/montant diffèrent selon la requête.
     *
     * @return array<int, Expression|string>
     */
    private function selectColumns(string $seanceCol, string $montantCol): array
    {
        return [
            'tx.date as Date',
            'tx.numero_piece as N° pièce',
            'tx.reference as Référence',
            'tx.mode_paiement as Mode paiement',
            'tx.libelle as Libellé',
            DB::raw("CASE WHEN tiers.type = 'entreprise' THEN COALESCE(tiers.entreprise, tiers.nom, '') ELSE TRIM(CONCAT(COALESCE(tiers.prenom, ''), ' ', COALESCE(tiers.nom, ''))) END as Tiers"),
            'tiers.type as Type tiers',
            'cpt.intitule as Compte comptable',
            DB::raw("COALESCE(CONCAT(f.code, ' — ', f.nom), cpt.numero_pcg) as Famille"),
            'tx.type as Type',
            'cb.nom as Compte bancaire',
            'op.nom as Opération',
            'topo.nom as Type opération',
            $seanceCol.' as Séance n°',
            $montantCol.' as Montant',
        ];
    }

    /**
     * Enrichissement PHP commun : Date formatée, Montant signé via Type, dimensions temporelles.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function enrich(array $data, int $exercice): array
    {
        $date = ! empty($data['Date']) ? Carbon::parse((string) $data['Date']) : null;
        $data['Date'] = $date?->format('d/m/Y');

        $signe = $data['Type'] === 'depense' ? -1 : 1;
        $data['Montant'] = $signe * abs((float) ($data['Montant'] ?? 0));

        if ($date) {
            $data['Mois'] = ucfirst($date->translatedFormat('F Y'));
            $data['Trimestre'] = $this->trimestreFor($date->month).' '.$exercice.'-'.($exercice + 1);
            $data['Semestre'] = $this->semestreFor($date->month).' '.$exercice.'-'.($exercice + 1);
        } else {
            $data['Mois'] = null;
            $data['Trimestre'] = null;
            $data['Semestre'] = null;
        }

        return $data;
    }

    /**
     * Trimestre relatif à exercice_mois_debut (TenantContext). Offset 1–3 → T1, … 10–12 → T4.
     * (Déplacé depuis AnalysePivot.)
     */
    private function trimestreFor(int $month): string
    {
        $moisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;
        $offset = (($month - $moisDebut + 12) % 12) + 1;

        return 'T'.(int) ceil($offset / 3);
    }

    /**
     * Semestre relatif à exercice_mois_debut (TenantContext). Offset 1–6 → S1, 7–12 → S2.
     * (Déplacé depuis AnalysePivot.)
     */
    private function semestreFor(int $month): string
    {
        $moisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;
        $offset = (($month - $moisDebut + 12) % 12) + 1;

        return $offset <= 6 ? 'S1' : 'S2';
    }
}
