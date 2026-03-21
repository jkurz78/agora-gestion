<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;

it('retourne la bonne URL de base pour production', function () {
    expect(HelloAssoEnvironnement::Production->baseUrl())
        ->toBe('https://api.helloasso.com');
});

it('retourne la bonne URL de base pour sandbox', function () {
    expect(HelloAssoEnvironnement::Sandbox->baseUrl())
        ->toBe('https://api.helloasso-sandbox.com');
});

it('retourne la bonne URL admin pour production', function () {
    expect(HelloAssoEnvironnement::Production->adminUrl())
        ->toBe('https://admin.helloasso.com');
});

it('retourne la bonne URL admin pour sandbox', function () {
    expect(HelloAssoEnvironnement::Sandbox->adminUrl())
        ->toBe('https://admin.helloasso-sandbox.com');
});

it('retourne le bon label', function () {
    expect(HelloAssoEnvironnement::Production->label())->toBe('Production');
    expect(HelloAssoEnvironnement::Sandbox->label())->toBe('Sandbox');
});

it('peut être casté depuis la valeur string production', function () {
    expect(HelloAssoEnvironnement::from('production'))
        ->toBe(HelloAssoEnvironnement::Production);
});
