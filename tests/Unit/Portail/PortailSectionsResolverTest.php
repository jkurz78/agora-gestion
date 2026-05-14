<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\Portail\PortailSectionsResolver;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;
use Illuminate\Support\Collection;

/**
 * Tiers is final, so we cannot Mockery::mock() it.
 * We use a real (unsaved) instance instead and let providers receive it directly.
 */
function makeTiers(): Tiers
{
    return new Tiers;
}

it('returns empty collection when no providers are registered', function (): void {
    $tiers = makeTiers();
    $resolver = new PortailSectionsResolver;

    $result = $resolver->resolve($tiers);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result)->toBeEmpty();
});

it('returns sections ordered by ordre asc when multiple providers registered', function (): void {
    $tiers = makeTiers();
    $resolver = new PortailSectionsResolver;

    $dtoB = new PortailSectionDTO('b', 'Section B', 'portail.b', 'bi-b', 20, null, true, null);
    $dtoA = new PortailSectionDTO('a', 'Section A', 'portail.a', 'bi-a', 10, null, true, null);
    $dtoC = new PortailSectionDTO('c', 'Section C', 'portail.c', 'bi-c', 30, null, true, null);

    $providerB = new class($dtoB) implements PortailSectionProvider
    {
        public function __construct(private readonly PortailSectionDTO $dto) {}

        public function resolve(Tiers $tiers): ?PortailSectionDTO
        {
            return $this->dto;
        }
    };

    $providerA = new class($dtoA) implements PortailSectionProvider
    {
        public function __construct(private readonly PortailSectionDTO $dto) {}

        public function resolve(Tiers $tiers): ?PortailSectionDTO
        {
            return $this->dto;
        }
    };

    $providerC = new class($dtoC) implements PortailSectionProvider
    {
        public function __construct(private readonly PortailSectionDTO $dto) {}

        public function resolve(Tiers $tiers): ?PortailSectionDTO
        {
            return $this->dto;
        }
    };

    $resolver->register($providerB);
    $resolver->register($providerA);
    $resolver->register($providerC);

    $result = $resolver->resolve($tiers);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result)->toHaveCount(3)
        ->and($result->values()->get(0)->id)->toBe('a')
        ->and($result->values()->get(1)->id)->toBe('b')
        ->and($result->values()->get(2)->id)->toBe('c');
});

it('excludes section when provider returns null', function (): void {
    $tiers = makeTiers();
    $resolver = new PortailSectionsResolver;

    $dto = new PortailSectionDTO('visible', 'Visible', 'portail.visible', 'bi-eye', 10, null, true, null);

    $visibleProvider = new class($dto) implements PortailSectionProvider
    {
        public function __construct(private readonly PortailSectionDTO $dto) {}

        public function resolve(Tiers $tiers): ?PortailSectionDTO
        {
            return $this->dto;
        }
    };

    $nullProvider = new class implements PortailSectionProvider
    {
        public function resolve(Tiers $tiers): ?PortailSectionDTO
        {
            return null;
        }
    };

    $resolver->register($visibleProvider);
    $resolver->register($nullProvider);

    $result = $resolver->resolve($tiers);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe('visible');
});

it('returns empty collection when tiers is null (unauthenticated)', function (): void {
    $resolver = new PortailSectionsResolver;
    $providerCalled = false;

    $provider = new class($providerCalled) implements PortailSectionProvider
    {
        public function __construct(private bool &$called) {}

        public function resolve(Tiers $tiers): ?PortailSectionDTO
        {
            $this->called = true;

            return null;
        }
    };

    $resolver->register($provider);

    $result = $resolver->resolve(null);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result)->toBeEmpty()
        ->and($providerCalled)->toBeFalse();
});
