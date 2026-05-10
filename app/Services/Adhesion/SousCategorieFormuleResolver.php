<?php

declare(strict_types=1);

namespace App\Services\Adhesion;

use App\Models\FormuleAdhesion;

final class SousCategorieFormuleResolver
{
    /** @var array<int, ?FormuleAdhesion> */
    private array $cache = [];

    public function resolve(int $sousCategorieId): ?FormuleAdhesion
    {
        if (! array_key_exists($sousCategorieId, $this->cache)) {
            // Priorité 2 du resolver : ne retourne QUE des formules manuelles.
            // Les formules HelloAsso sont résolues en priorité 1 via
            // (helloasso_form_slug, helloasso_tier_id) — voir AdhesionService::resolveFormule.
            $this->cache[$sousCategorieId] = FormuleAdhesion::query()
                ->where('sous_categorie_id', $sousCategorieId)
                ->where('actif', true)
                ->where('est_helloasso', false)
                ->first();
        }

        return $this->cache[$sousCategorieId];
    }

    public function forget(int $sousCategorieId): void
    {
        unset($this->cache[$sousCategorieId]);
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
