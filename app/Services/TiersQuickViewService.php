<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutFacture;
use App\Enums\TypeTransaction;
use App\Models\Facture;
use App\Models\Participant;
use App\Models\Tiers;
use Illuminate\Support\Facades\DB;

final class TiersQuickViewService
{
    /**
     * @return array<string, mixed>
     */
    public function getSummary(Tiers $tiers, int $exercice): array
    {
        $summary = [];

        $summary['contact'] = $this->getContact($tiers);

        $depenses = $this->getDepenses($tiers, $exercice);
        if ($depenses !== null) {
            $summary['depenses'] = $depenses;
        }

        $recettes = $this->getRecettes($tiers, $exercice);
        if ($recettes !== null) {
            $summary['recettes'] = $recettes;
        }

        $dons = $this->getDons($tiers, $exercice);
        if ($dons !== null) {
            $summary['dons'] = $dons;
        }

        $cotisations = $this->getCotisations($tiers, $exercice);
        if ($cotisations !== null) {
            $summary['cotisations'] = $cotisations;
        }

        $participations = $this->getParticipations($tiers);
        if ($participations !== null) {
            $summary['participations'] = $participations;
        }

        $referent = $this->getReferent($tiers);
        if ($referent !== null) {
            $summary['referent'] = $referent;
        }

        $factures = $this->getFactures($tiers, $exercice);
        if ($factures !== null) {
            $summary['factures'] = $factures;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function getContact(Tiers $tiers): array
    {
        return [
            'email' => $tiers->email,
            'telephone' => $tiers->telephone,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDepenses(Tiers $tiers, int $exercice): ?array
    {
        $dateDebut = "{$exercice}-09-01";
        $dateFin = ($exercice + 1).'-08-31';

        $row = DB::table('transactions as tx')
            ->join('transaction_lignes as tl', 'tl.transaction_id', '=', 'tx.id')
            ->where('tx.tiers_id', $tiers->id)
            ->where('tx.type', TypeTransaction::Depense->value)
            ->whereBetween('tx.date', [$dateDebut, $dateFin])
            ->whereNull('tx.deleted_at')
            ->whereNull('tl.deleted_at')
            ->selectRaw('COUNT(DISTINCT tx.id) as count, SUM(tx.montant_total) as total')
            ->first();

        if ($row === null || (int) $row->count === 0) {
            return null;
        }

        $parOperation = DB::table('transactions as tx')
            ->join('transaction_lignes as tl', 'tl.transaction_id', '=', 'tx.id')
            ->leftJoin('operations as op', 'op.id', '=', 'tl.operation_id')
            ->where('tx.tiers_id', $tiers->id)
            ->where('tx.type', TypeTransaction::Depense->value)
            ->whereBetween('tx.date', [$dateDebut, $dateFin])
            ->whereNull('tx.deleted_at')
            ->whereNull('tl.deleted_at')
            ->whereNotNull('tl.operation_id')
            ->selectRaw('tl.operation_id, op.nom as operation_nom, COUNT(DISTINCT tx.id) as count, SUM(tx.montant_total) as total')
            ->groupBy('tl.operation_id', 'op.nom')
            ->get()
            ->map(fn (object $r): array => [
                'operation_id' => (int) $r->operation_id,
                'operation_nom' => $r->operation_nom,
                'count' => (int) $r->count,
                'total' => $r->total,
            ])
            ->values()
            ->all();

        $result = [
            'count' => (int) $row->count,
            'total' => $row->total,
        ];

        if (count($parOperation) > 0) {
            $result['par_operation'] = $parOperation;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRecettes(Tiers $tiers, int $exercice): ?array
    {
        $dateDebut = "{$exercice}-09-01";
        $dateFin = ($exercice + 1).'-08-31';

        $row = DB::table('transactions as tx')
            ->join('transaction_lignes as tl', 'tl.transaction_id', '=', 'tx.id')
            ->join('sous_categories as sc', 'sc.id', '=', 'tl.sous_categorie_id')
            ->where('tx.tiers_id', $tiers->id)
            ->where('tx.type', TypeTransaction::Recette->value)
            ->whereBetween('tx.date', [$dateDebut, $dateFin])
            ->whereNull('tx.deleted_at')
            ->whereNull('tl.deleted_at')
            ->where('sc.pour_dons', false)
            ->where('sc.pour_cotisations', false)
            ->selectRaw('COUNT(DISTINCT tx.id) as count, SUM(tx.montant_total) as total')
            ->first();

        if ($row === null || (int) $row->count === 0) {
            return null;
        }

        return [
            'count' => (int) $row->count,
            'total' => $row->total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDons(Tiers $tiers, int $exercice): ?array
    {
        $dateDebut = "{$exercice}-09-01";
        $dateFin = ($exercice + 1).'-08-31';

        $row = DB::table('transactions as tx')
            ->join('transaction_lignes as tl', 'tl.transaction_id', '=', 'tx.id')
            ->join('sous_categories as sc', 'sc.id', '=', 'tl.sous_categorie_id')
            ->where('tx.tiers_id', $tiers->id)
            ->where('tx.type', TypeTransaction::Recette->value)
            ->whereBetween('tx.date', [$dateDebut, $dateFin])
            ->whereNull('tx.deleted_at')
            ->whereNull('tl.deleted_at')
            ->where('sc.pour_dons', true)
            ->selectRaw('COUNT(DISTINCT tx.id) as count, SUM(tx.montant_total) as total')
            ->first();

        if ($row === null || (int) $row->count === 0) {
            return null;
        }

        return [
            'count' => (int) $row->count,
            'total' => $row->total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCotisations(Tiers $tiers, int $exercice): ?array
    {
        $dateDebut = "{$exercice}-09-01";
        $dateFin = ($exercice + 1).'-08-31';

        $row = DB::table('transactions as tx')
            ->join('transaction_lignes as tl', 'tl.transaction_id', '=', 'tx.id')
            ->join('sous_categories as sc', 'sc.id', '=', 'tl.sous_categorie_id')
            ->where('tx.tiers_id', $tiers->id)
            ->where('tx.type', TypeTransaction::Recette->value)
            ->whereBetween('tx.date', [$dateDebut, $dateFin])
            ->whereNull('tx.deleted_at')
            ->whereNull('tl.deleted_at')
            ->where('sc.pour_cotisations', true)
            ->selectRaw('COUNT(DISTINCT tx.id) as count, SUM(tx.montant_total) as total')
            ->first();

        if ($row === null || (int) $row->count === 0) {
            return null;
        }

        return [
            'count' => (int) $row->count,
            'total' => $row->total,
        ];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function getParticipations(Tiers $tiers): ?array
    {
        $participations = Participant::query()
            ->where('tiers_id', $tiers->id)
            ->with('operation:id,nom,date_debut')
            ->get()
            ->filter(fn (Participant $p): bool => $p->operation !== null)
            ->map(fn (Participant $p): array => [
                'operation_id' => $p->operation->id,
                'operation_nom' => $p->operation->nom,
                'date_debut' => $p->operation->date_debut,
            ])
            ->values()
            ->all();

        return count($participations) > 0 ? $participations : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getReferent(Tiers $tiers): ?array
    {
        if (! auth()->check() || ! auth()->user()->peut_voir_donnees_sensibles) {
            return null;
        }

        $participant = Participant::query()
            ->where('tiers_id', $tiers->id)
            ->where(function ($q): void {
                $q->whereNotNull('refere_par_id')
                    ->orWhereNotNull('medecin_tiers_id')
                    ->orWhereNotNull('therapeute_tiers_id');
            })
            ->with([
                'referePar:id,nom,prenom',
                'medecinTiers:id,nom,prenom',
                'therapeuteTiers:id,nom,prenom',
            ])
            ->first();

        if ($participant === null) {
            return null;
        }

        $referent = [];

        if ($participant->referePar !== null) {
            $referent['refere_par'] = $participant->referePar;
        }

        if ($participant->medecinTiers !== null) {
            $referent['medecin'] = $participant->medecinTiers;
        }

        if ($participant->therapeuteTiers !== null) {
            $referent['therapeute'] = $participant->therapeuteTiers;
        }

        return count($referent) > 0 ? $referent : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getFactures(Tiers $tiers, int $exercice): ?array
    {
        $factures = Facture::query()
            ->where('tiers_id', $tiers->id)
            ->where('exercice', $exercice)
            ->where('statut', StatutFacture::Validee)
            ->get();

        if ($factures->isEmpty()) {
            return null;
        }

        $count = $factures->count();
        $total = $factures->sum('montant_total');
        $impayees = $factures->filter(fn (Facture $f): bool => ! $f->isAcquittee())->count();

        return [
            'count' => $count,
            'total' => $total,
            'impayees' => $impayees,
        ];
    }
}
