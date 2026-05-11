<?php

declare(strict_types=1);

namespace App\Services\Tiers;

use App\Enums\StatutReglement;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\Tiers\DTO\AReferreLigneDTO;
use App\Services\Tiers\DTO\AReferreTimelineDTO;
use App\Services\Tiers\DTO\ParticipationLigneDTO;
use App\Services\Tiers\DTO\ParticipationsTimelineDTO;
use App\Services\Tiers\DTO\SuitLigneDTO;
use App\Services\Tiers\DTO\SuitTimelineDTO;
use Illuminate\Support\Facades\DB;

final class TiersOperationsTimelineService
{
    public function forTiers(Tiers $tiers): ParticipationsTimelineDTO
    {
        $participants = Participant::query()
            ->where('tiers_id', $tiers->id)
            ->with([
                'operation' => fn ($q) => $q->withTrashed()->select('id', 'nom', 'type_operation_id', 'deleted_at'),
                'operation.typeOperation:id,nom',
                'operation.seances:id,operation_id,date',
                'typeOperationTarif:id,libelle,montant',
                'referePar:id,nom,prenom',
                'presences:id,participant_id,seance_id,statut',
                'reglements:id,participant_id,seance_id,montant_prevu',
                'reglements.transaction:id,reglement_id,statut_reglement',
            ])
            ->orderByDesc('date_inscription')
            ->get();

        $operationIds = $participants->pluck('operation_id')->unique()->values()->all();

        $montantsPayes = [];

        if (! empty($operationIds)) {
            $montantsPayes = TransactionLigne::query()
                ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
                ->where('transactions.tiers_id', $tiers->id)
                ->whereIn('transaction_lignes.operation_id', $operationIds)
                ->whereIn('transactions.statut_reglement', [
                    StatutReglement::Recu->value,
                    StatutReglement::Pointe->value,
                ])
                ->whereNull('transaction_lignes.deleted_at')
                ->groupBy('transaction_lignes.operation_id')
                ->select('transaction_lignes.operation_id', DB::raw('SUM(transaction_lignes.montant) as total'))
                ->pluck('total', 'transaction_lignes.operation_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        $lignes = $participants->map(function (Participant $p) use ($montantsPayes): ParticipationLigneDTO {
            return new ParticipationLigneDTO(
                participant: $p,
                montantPayePrecalcule: (float) ($montantsPayes[(int) $p->operation_id] ?? 0.0),
            );
        })->all();

        return new ParticipationsTimelineDTO(
            lignes: $lignes,
            totalCount: count($lignes),
        );
    }

    public function aReferreForTiers(Tiers $tiers): AReferreTimelineDTO
    {
        $totalCount = Participant::where('refere_par_id', $tiers->id)
            ->distinct()
            ->count('tiers_id');

        if ($totalCount === 0) {
            return new AReferreTimelineDTO(lignes: [], totalCount: 0);
        }

        $participants = Participant::query()
            ->where('refere_par_id', $tiers->id)
            ->with([
                'tiers:id,nom,prenom',
                'operation' => fn ($q) => $q->withTrashed()->select('id', 'nom', 'type_operation_id', 'deleted_at'),
                'operation.typeOperation:id,nom',
                'operation.seances:id,operation_id,date',
            ])
            ->join('tiers as t', 't.id', '=', 'participants.tiers_id')
            ->orderBy('t.nom')
            ->orderBy('t.prenom')
            ->select('participants.*')
            ->get();

        // Tri secondaire : date opération desc en collection (après tri primaire SQL)
        $sorted = $participants->sort(function (Participant $a, Participant $b) {
            $cmp = strcmp((string) $a->tiers->nom, (string) $b->tiers->nom);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string) $a->tiers->prenom, (string) $b->tiers->prenom);
            if ($cmp !== 0) {
                return $cmp;
            }
            // Tri secondaire : date opération desc (max séance, nulls en queue)
            $da = $a->operation->seances->pluck('date')->max();
            $db = $b->operation->seances->pluck('date')->max();
            if ($da === null && $db === null) {
                return 0;
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }

            return $db <=> $da;
        })->values();

        $lignes = $sorted->map(fn (Participant $p) => new AReferreLigneDTO(participant: $p))->all();

        return new AReferreTimelineDTO(lignes: $lignes, totalCount: $totalCount);
    }

    public function suitForTiers(Tiers $tiers): SuitTimelineDTO
    {
        $totalCount = Participant::where(fn ($q) => $q
            ->where('medecin_tiers_id', $tiers->id)
            ->orWhere('therapeute_tiers_id', $tiers->id)
        )->distinct()->count('tiers_id');

        if ($totalCount === 0) {
            return new SuitTimelineDTO(lignes: [], totalCount: 0);
        }

        $eagerLoad = [
            'tiers:id,nom,prenom',
            'operation' => fn ($q) => $q->withTrashed()->select('id', 'nom', 'type_operation_id', 'deleted_at'),
            'operation.typeOperation:id,nom',
            'operation.seances:id,operation_id,date',
        ];

        $medecins = Participant::query()
            ->where('medecin_tiers_id', $tiers->id)
            ->with($eagerLoad)
            ->get()
            ->map(fn (Participant $p) => ['p' => $p, 'qualite' => 'medecin']);

        $therapeutes = Participant::query()
            ->where('therapeute_tiers_id', $tiers->id)
            ->with($eagerLoad)
            ->get()
            ->map(fn (Participant $p) => ['p' => $p, 'qualite' => 'therapeute']);

        $all = $medecins->concat($therapeutes);

        $sorted = $all->sort(function ($a, $b) {
            $cmp = strcmp((string) $a['p']->tiers->nom, (string) $b['p']->tiers->nom);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string) $a['p']->tiers->prenom, (string) $b['p']->tiers->prenom);
            if ($cmp !== 0) {
                return $cmp;
            }
            // Tri secondaire : date opération desc (max séance, nulls en queue)
            $da = $a['p']->operation->seances->pluck('date')->max();
            $db = $b['p']->operation->seances->pluck('date')->max();
            if ($da === null && $db === null) {
                return 0;
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }

            return $db <=> $da;
        })->values();

        $lignes = $sorted->map(fn ($e) => new SuitLigneDTO(participant: $e['p'], qualite: $e['qualite']))->all();

        return new SuitTimelineDTO(lignes: $lignes, totalCount: $totalCount);
    }
}
