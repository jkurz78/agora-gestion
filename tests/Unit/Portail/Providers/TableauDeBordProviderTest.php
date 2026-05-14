<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\Portail\Providers\TableauDeBordProvider;

it('returns the tableau-de-bord DTO for any Tiers', function (): void {
    $tiers = new Tiers();
    $provider = new TableauDeBordProvider();

    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull()
        ->and($dto->id)->toBe('tableau-de-bord')
        ->and($dto->label)->toBe('Tableau de bord')
        ->and($dto->routeName)->toBe('portail.home')
        ->and($dto->icon)->toBe('bi-house-door')
        ->and($dto->ordre)->toBe(10)
        ->and($dto->groupe)->toBe('Espace personnel')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});
