<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\Tiers;
use App\Services\Portail\Providers\MesAdhesionsProvider;

it('returns DTO when tiers has at least 1 adhesion', function (): void {
    $tiers = Tiers::factory()->create();
    Adhesion::factory()->create(['tiers_id' => $tiers->id]);
    $provider = new MesAdhesionsProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull()
        ->and($dto->id)->toBe('mes-adhesions')
        ->and($dto->label)->toBe('Mes adhésions')
        ->and($dto->routeName)->toBe('portail.mes-adhesions')
        ->and($dto->icon)->toBe('bi-card-checklist')
        ->and($dto->ordre)->toBe(60)
        ->and($dto->groupe)->toBe('Ma vie de membre')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});

it('returns null when tiers has no adhesion', function (): void {
    $tiers = Tiers::factory()->create();
    $provider = new MesAdhesionsProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->toBeNull();
});
