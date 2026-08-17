<?php

declare(strict_types=1);

use App\Enums\PorteeExercices;

it('sérialise vers current et all', function () {
    expect(PorteeExercices::Courant->value)->toBe('current')
        ->and(PorteeExercices::Tous->value)->toBe('all');
});

it('retombe sur current pour une valeur inconnue', function () {
    expect(PorteeExercices::depuisRequete('nimportequoi'))->toBe(PorteeExercices::Courant)
        ->and(PorteeExercices::depuisRequete(null))->toBe(PorteeExercices::Courant)
        ->and(PorteeExercices::depuisRequete(''))->toBe(PorteeExercices::Courant);
});

it('reconnaît une valeur valide', function () {
    expect(PorteeExercices::depuisRequete('all'))->toBe(PorteeExercices::Tous)
        ->and(PorteeExercices::depuisRequete('current'))->toBe(PorteeExercices::Courant);
});

it('expose si la portée est bornée à l\'exercice affiché', function () {
    expect(PorteeExercices::Courant->estBornee())->toBeTrue()
        ->and(PorteeExercices::Tous->estBornee())->toBeFalse();
});
