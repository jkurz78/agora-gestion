<?php

declare(strict_types=1);

namespace App\Services\Tiers;

use App\Enums\StatutReglement;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\Tiers\DTO\ParticipationLigneDTO;
use App\Services\Tiers\DTO\ParticipationsTimelineDTO;
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
}
