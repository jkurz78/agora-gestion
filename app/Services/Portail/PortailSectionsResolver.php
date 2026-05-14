<?php

declare(strict_types=1);

namespace App\Services\Portail;

use App\Models\Tiers;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;
use Illuminate\Support\Collection;

final class PortailSectionsResolver
{
    /** @var list<PortailSectionProvider> */
    private array $providers = [];

    public function register(PortailSectionProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @return Collection<int, PortailSectionDTO>
     */
    public function resolve(?Tiers $tiers): Collection
    {
        if ($tiers === null) {
            return collect();
        }

        return collect($this->providers)
            ->map(fn (PortailSectionProvider $p): ?PortailSectionDTO => $p->resolve($tiers))
            ->filter()
            ->sortBy(fn (PortailSectionDTO $dto): int => $dto->ordre)
            ->values();
    }
}
