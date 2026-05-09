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
            $this->cache[$sousCategorieId] = FormuleAdhesion::query()
                ->where('sous_categorie_id', $sousCategorieId)
                ->where('actif', true)
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
