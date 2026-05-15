<?php

declare(strict_types=1);

use App\Models\Participant;
use App\Models\Tiers;
use App\Services\Portail\Providers\MesActivitesProvider;

it('returns DTO when tiers has at least 1 participation', function (): void {
    $tiers = Tiers::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id]);

    $provider = new MesActivitesProvider;
    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull()
        ->and($dto->id)->toBe('mes-activites')
        ->and($dto->label)->toBe('Mes activités')
        ->and($dto->routeName)->toBe('portail.mes-activites')
        ->and($dto->icon)->toBe('bi-calendar-event')
        ->and($dto->ordre)->toBe(80)
        ->and($dto->groupe)->toBe('Mes activités')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});

it('returns null when tiers has no participation', function (): void {
    $tiers = Tiers::factory()->create();

    $provider = new MesActivitesProvider;
    $dto = $provider->resolve($tiers);

    expect($dto)->toBeNull();
});
