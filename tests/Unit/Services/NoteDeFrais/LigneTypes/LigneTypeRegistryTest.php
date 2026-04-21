<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Services\NoteDeFrais\LigneTypes\KilometriqueLigneType;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
use App\Services\NoteDeFrais\LigneTypes\StandardLigneType;

it('résout Standard vers StandardLigneType', function () {
    $registry = new LigneTypeRegistry;
    expect($registry->for(NoteDeFraisLigneType::Standard))->toBeInstanceOf(StandardLigneType::class);
});

it('résout Kilometrique vers KilometriqueLigneType', function () {
    $registry = new LigneTypeRegistry;
    expect($registry->for(NoteDeFraisLigneType::Kilometrique))->toBeInstanceOf(KilometriqueLigneType::class);
});

it('est un singleton via le container', function () {
    $first = app(LigneTypeRegistry::class);
    $second = app(LigneTypeRegistry::class);
    expect($first)->toBe($second);
});
