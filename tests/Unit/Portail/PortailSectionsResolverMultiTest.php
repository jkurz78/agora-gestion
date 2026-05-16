<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\Portail\PortailSectionsResolver;
use App\Support\Portail\PortailMultiSectionsProvider;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;
use Illuminate\Support\Collection;

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Provider multi qui retourne 0 sections → rien dans la collection
// ─────────────────────────────────────────────────────────────────────────────
it('multi-provider qui retourne 0 sections n\'ajoute rien à la collection', function (): void {
    $tiers = new Tiers;
    $resolver = new PortailSectionsResolver;

    $provider = new class implements PortailMultiSectionsProvider
    {
        public function resolveAll(Tiers $tiers): iterable
        {
            return [];
        }
    };

    $resolver->registerMulti($provider);

    $result = $resolver->resolve($tiers);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result)->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Provider multi retourne 2 sections → 2 dans la collection, ordonnées
// ─────────────────────────────────────────────────────────────────────────────
it('multi-provider qui retourne 2 sections les ajoute ordonnées dans la collection', function (): void {
    $tiers = new Tiers;
    $resolver = new PortailSectionsResolver;

    $dto80 = new PortailSectionDTO('type-80', 'Mes ateliers', 'portail.mes-activites.show', 'bi-x', 80, 'Mes activités', true, null, ['typeOperation' => 1]);
    $dto81 = new PortailSectionDTO('type-81', 'Mes formations', 'portail.mes-activites.show', 'bi-x', 81, 'Mes activités', true, null, ['typeOperation' => 2]);

    $provider = new class($dto80, $dto81) implements PortailMultiSectionsProvider
    {
        public function __construct(
            private readonly PortailSectionDTO $a,
            private readonly PortailSectionDTO $b,
        ) {}

        public function resolveAll(Tiers $tiers): iterable
        {
            yield $this->b; // volontairement inversé pour vérifier le tri
            yield $this->a;
        }
    };

    $resolver->registerMulti($provider);

    $result = $resolver->resolve($tiers);

    expect($result)->toHaveCount(2)
        ->and($result->values()->get(0)->id)->toBe('type-80') // ordre 80 avant 81
        ->and($result->values()->get(1)->id)->toBe('type-81');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Mix single (ordre 10) + multi (ordres 80 et 90) → 3 sections 10,80,90
// ─────────────────────────────────────────────────────────────────────────────
it('mélange single + multi-provider : sections triées 10, 80, 90', function (): void {
    $tiers = new Tiers;
    $resolver = new PortailSectionsResolver;

    $dtoSingle = new PortailSectionDTO('single', 'Mon profil', 'portail.mon-profil', 'bi-person', 10, null, true, null);

    $singleProvider = new class($dtoSingle) implements PortailSectionProvider
    {
        public function __construct(private readonly PortailSectionDTO $dto) {}

        public function resolve(Tiers $tiers): ?PortailSectionDTO
        {
            return $this->dto;
        }
    };

    $dto80 = new PortailSectionDTO('m-80', 'Mes ateliers', 'portail.mes-activites.show', 'bi-x', 80, 'Mes activités', true, null, ['typeOperation' => 1]);
    $dto90 = new PortailSectionDTO('m-90', 'Mes parcours', 'portail.mes-activites.show', 'bi-x', 90, 'Mes activités', true, null, ['typeOperation' => 2]);

    $multiProvider = new class($dto80, $dto90) implements PortailMultiSectionsProvider
    {
        public function __construct(
            private readonly PortailSectionDTO $a,
            private readonly PortailSectionDTO $b,
        ) {}

        public function resolveAll(Tiers $tiers): iterable
        {
            yield $this->b; // inversé
            yield $this->a;
        }
    };

    $resolver->register($singleProvider);
    $resolver->registerMulti($multiProvider);

    $result = $resolver->resolve($tiers);

    expect($result)->toHaveCount(3)
        ->and($result->values()->get(0)->id)->toBe('single')  // ordre 10
        ->and($result->values()->get(1)->id)->toBe('m-80')    // ordre 80
        ->and($result->values()->get(2)->id)->toBe('m-90');   // ordre 90
});
