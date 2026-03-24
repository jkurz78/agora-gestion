<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Models\User;

test('Espace enum has compta and gestion cases', function (): void {
    expect(Espace::Compta->value)->toBe('compta');
    expect(Espace::Gestion->value)->toBe('gestion');
    expect(Espace::Compta->label())->toBe('Comptabilité');
    expect(Espace::Gestion->label())->toBe('Gestion');
    expect(Espace::Compta->color())->toBe('#722281');
    expect(Espace::Gestion->color())->toBe('#63B2EA');
});

test('user dernier_espace defaults to compta', function (): void {
    $user = User::factory()->create();
    expect($user->dernier_espace)->toBeInstanceOf(Espace::class);
    expect($user->dernier_espace)->toBe(Espace::Compta);
});

test('user dernier_espace can be set to gestion', function (): void {
    $user = User::factory()->create();
    $user->update(['dernier_espace' => Espace::Gestion]);
    $user->refresh();
    expect($user->dernier_espace)->toBe(Espace::Gestion);
});
