<?php

declare(strict_types=1);

namespace App\Services\Tiers;

use App\Models\Participant;
use App\Models\Tiers;
use App\Services\Tiers\DTO\ParticipationLigneDTO;
use App\Services\Tiers\DTO\ParticipationsTimelineDTO;

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

        $lignes = $participants->map(fn (Participant $p) => new ParticipationLigneDTO($p))->all();

        return new ParticipationsTimelineDTO(
            lignes: $lignes,
            totalCount: count($lignes),
        );
    }
}
