<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;

it('expose le case DonParAbandonCreances avec la bonne valeur', function () {
    expect(StatutNoteDeFrais::DonParAbandonCreances->value)->toBe('don_par_abandon_de_creances');
});

it('retourne le bon label pour DonParAbandonCreances', function () {
    expect(StatutNoteDeFrais::DonParAbandonCreances->label())->toBe('Don par abandon de créance');
});

it('expose les six cases attendus', function () {
    expect(StatutNoteDeFrais::cases())->toHaveCount(6);
});
