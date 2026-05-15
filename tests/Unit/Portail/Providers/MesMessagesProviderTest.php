<?php

declare(strict_types=1);

use App\Models\EmailLog;
use App\Models\Tiers;
use App\Services\Portail\Providers\MesMessagesProvider;

it('returns DTO when tiers has at least 1 EmailLog', function (): void {
    $tiers = Tiers::factory()->create();
    EmailLog::factory()->create(['tiers_id' => $tiers->id]);

    $provider = new MesMessagesProvider;
    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull()
        ->and($dto->id)->toBe('mes-messages')
        ->and($dto->label)->toBe('Mes messages')
        ->and($dto->routeName)->toBe('portail.mes-messages')
        ->and($dto->icon)->toBe('bi-envelope')
        ->and($dto->ordre)->toBe(90)
        ->and($dto->groupe)->toBe('Mes messages')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});

it('returns null when tiers has no EmailLog', function (): void {
    $tiers = Tiers::factory()->create();
    $provider = new MesMessagesProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->toBeNull();
});
