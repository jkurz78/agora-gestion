<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\Portail\Providers\FacturesPartenairesProvider;

it('returns DTO when pour_depenses=true', function (): void {
    $tiers = Tiers::factory()->create(['pour_depenses' => true]);
    $provider = new FacturesPartenairesProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull()
        ->and($dto->id)->toBe('factures-partenaires')
        ->and($dto->label)->toBe('Factures partenaires')
        ->and($dto->routeName)->toBe('portail.factures.index')
        ->and($dto->icon)->toBe('bi-file-earmark-text')
        ->and($dto->ordre)->toBe(40)
        ->and($dto->groupe)->toBe('Mes frais & factures')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});

it('returns null when pour_depenses=false', function (): void {
    $tiers = Tiers::factory()->create(['pour_depenses' => false]);
    $provider = new FacturesPartenairesProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->toBeNull();
});
