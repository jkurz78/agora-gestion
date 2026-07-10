<?php

declare(strict_types=1);

namespace App\Services\Adhesion;

use App\Models\FormuleAdhesion;

final class CompteFormuleResolver
{
    /** @var array<int, ?FormuleAdhesion> Cache par compte_id, durée de vie = requête (singleton). */
    private array $cache = [];

    /**
     * Résout la formule d'adhésion manuelle active portée par le compte de
     * ventilation (formules_adhesion.compte_id).
     *
     * Priorité 2 du resolver : ne retourne QUE des formules manuelles.
     * Les formules HelloAsso sont résolues en priorité 1 via
     * (helloasso_form_slug, helloasso_tier_id) — voir AdhesionService::resolveFormule.
     */
    public function resolve(int $compteId): ?FormuleAdhesion
    {
        if (! array_key_exists($compteId, $this->cache)) {
            $this->cache[$compteId] = FormuleAdhesion::query()
                ->where('compte_id', $compteId)
                ->where('actif', true)
                ->where('est_helloasso', false)
                ->first();
        }

        return $this->cache[$compteId];
    }

    public function forget(int $compteId): void
    {
        unset($this->cache[$compteId]);
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
