<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Services\Portail\Providers\MonProfilProvider;

it('returns the mon-profil DTO for any Tiers', function (): void {
    $tiers = new Tiers();
    $provider = new MonProfilProvider();

    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull()
        ->and($dto->id)->toBe('mon-profil')
        ->and($dto->label)->toBe('Mon profil')
        ->and($dto->routeName)->toBe('portail.mon-profil')
        ->and($dto->icon)->toBe('bi-person')
        ->and($dto->ordre)->toBe(20)
        ->and($dto->groupe)->toBe('Espace personnel')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});
