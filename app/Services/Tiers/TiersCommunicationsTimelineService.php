<?php

declare(strict_types=1);

namespace App\Services\Tiers;

use App\Models\EmailLog;
use App\Models\Tiers;
use App\Services\Tiers\DTO\CommunicationsTimelineDTO;
use App\Services\Tiers\DTO\EmailLogLigneDTO;
use Illuminate\Database\Eloquent\Builder;

final class TiersCommunicationsTimelineService
{
    public const PAGE_SIZE = 50;

    public function forTiers(
        Tiers $tiers,
        ?string $filtreCategorie = null,
        int $page = 1,
    ): CommunicationsTimelineDTO {
        $base = $this->baseQuery($tiers);

        $totalGlobal = (clone $base)->count();

        $compteursParCategorie = (clone $base)
            ->groupBy('categorie')
            ->selectRaw('categorie, COUNT(*) as nb')
            ->pluck('nb', 'categorie')
            ->map(fn ($v) => (int) $v)
            ->all();

        $paginator = (clone $base)
            ->when(
                $filtreCategorie !== null && $filtreCategorie !== '',
                fn (Builder $q) => $q->where('categorie', $filtreCategorie),
            )
            ->with([
                'participant:id,tiers_id',
                'participant.tiers:id,nom,prenom',
                'operation:id,nom',
                'campagne:id,objet',
                'envoyePar:id,nom',
                'opens',
            ])
            ->orderByDesc('created_at')
            ->paginate(self::PAGE_SIZE, ['*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (EmailLog $log) => EmailLogLigneDTO::fromEmailLog($log))
        );

        return new CommunicationsTimelineDTO(
            emails: $paginator,
            total: $totalGlobal,
            compteursParCategorie: $compteursParCategorie,
        );
    }

    public function countTotal(Tiers $tiers): int
    {
        return $this->baseQuery($tiers)->count();
    }

    private function baseQuery(Tiers $tiers): Builder
    {
        return EmailLog::query()
            ->where(function (Builder $q) use ($tiers): void {
                $q->where(function (Builder $inner) use ($tiers): void {
                    $inner->where('tiers_id', $tiers->id)
                        ->whereIn('tiers_id', Tiers::select('id'));
                })
                    ->orWhereIn(
                        'participant_id',
                        $tiers->participants()->select('id')
                    );
            });
    }
}
