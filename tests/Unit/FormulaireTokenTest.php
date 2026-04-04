<?php

declare(strict_types=1);

use App\Models\FormulaireToken;

it('returns true when expire_at is in the past', function () {
    $token = new FormulaireToken(['expire_at' => now()->subDay()]);
    expect($token->isExpire())->toBeTrue();
});

it('returns false when expire_at is in the future', function () {
    $token = new FormulaireToken(['expire_at' => now()->addDay()]);
    expect($token->isExpire())->toBeFalse();
});

it('returns false when expire_at is null', function () {
    $token = new FormulaireToken(['expire_at' => null]);
    expect($token->isExpire())->toBeFalse();
});

it('isValide returns false when expire_at is null', function () {
    $token = new FormulaireToken(['expire_at' => null, 'rempli_at' => null]);
    expect($token->isValide())->toBeFalse();
});

it('isValide returns false when already used', function () {
    $token = new FormulaireToken([
        'expire_at' => now()->addDay(),
        'rempli_at' => now(),
    ]);
    expect($token->isValide())->toBeFalse();
});

it('isValide returns true when not expired and not used', function () {
    $token = new FormulaireToken([
        'expire_at' => now()->addDay(),
        'rempli_at' => null,
    ]);
    expect($token->isValide())->toBeTrue();
});
