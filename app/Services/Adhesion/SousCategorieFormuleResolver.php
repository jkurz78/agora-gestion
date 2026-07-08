<?php

declare(strict_types=1);

namespace App\Services\Adhesion;

use App\Models\FormuleAdhesion;

final class SousCategorieFormuleResolver
{
    /** @var array<int, ?FormuleAdhesion> */
    private array $cache = [];

    /** @var array<int, ?FormuleAdhesion> DC-5 — cache dédié à la résolution par compte_id. */
    private array $cacheParCompte = [];

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

    /**
     * DC-5 — même résolution que {@see resolve()} mais par compte_id (dissolution
     * sous_categories → comptes). Utilisée quand la ligne cotisation porte déjà
     * compte_id (ligne enrichie partie double).
     */
    public function resolveParCompte(int $compteId): ?FormuleAdhesion
    {
        if (! array_key_exists($compteId, $this->cacheParCompte)) {
            $this->cacheParCompte[$compteId] = FormuleAdhesion::query()
                ->where('compte_id', $compteId)
                ->where('actif', true)
                ->where('est_helloasso', false)
                ->first();
        }

        return $this->cacheParCompte[$compteId];
    }

    public function forget(int $sousCategorieId): void
    {
        unset($this->cache[$sousCategorieId]);
    }

    public function flush(): void
    {
        $this->cache = [];
        $this->cacheParCompte = [];
    }
}
