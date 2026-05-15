<?php

declare(strict_types=1);

namespace App\Services\Portail;

use App\Models\Tiers;
use App\Support\Portail\PortailMultiSectionsProvider;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;
use Illuminate\Support\Collection;

final class PortailSectionsResolver
{
    /** @var list<PortailSectionProvider> */
    private array $providers = [];

    /** @var list<PortailMultiSectionsProvider> */
    private array $multiProviders = [];

    public function register(PortailSectionProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    public function registerMulti(PortailMultiSectionsProvider $provider): void
    {
        $this->multiProviders[] = $provider;
    }

    /**
     * @return Collection<int, PortailSectionDTO>
     */
    public function resolve(?Tiers $tiers): Collection
    {
        if ($tiers === null) {
            return collect();
        }

        $single = collect($this->providers)
            ->map(fn (PortailSectionProvider $p): ?PortailSectionDTO => $p->resolve($tiers))
            ->filter();

        $multi = collect($this->multiProviders)
            ->flatMap(fn (PortailMultiSectionsProvider $p): array => iterator_to_array($p->resolveAll($tiers)));

        return $single->merge($multi)
            ->sortBy(fn (PortailSectionDTO $dto): int => $dto->ordre)
            ->values();
    }
}
