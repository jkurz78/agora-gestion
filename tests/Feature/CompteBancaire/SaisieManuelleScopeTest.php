<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('returns active accounts that are not externally fed', function () {
    CompteBancaire::factory()->create([
        'nom' => 'Compte courant',
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
    ]);

    $result = CompteBancaire::saisieManuelle()->get();

    expect($result->pluck('nom')->all())->toContain('Compte courant');
});

it('excludes archived accounts', function () {
    CompteBancaire::factory()->create([
        'nom' => 'Compte archivé',
        'actif_recettes_depenses' => false,
        'saisie_automatisee' => false,
    ]);

    $result = CompteBancaire::saisieManuelle()->get();

    expect($result->pluck('nom')->all())->not->toContain('Compte archivé');
});

it('excludes externally fed accounts (HelloAsso)', function () {
    CompteBancaire::factory()->create([
        'nom' => 'HelloAsso',
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => true,
    ]);

    $result = CompteBancaire::saisieManuelle()->get();

    expect($result->pluck('nom')->all())->not->toContain('HelloAsso');
});

it('respects tenant scope', function () {
    CompteBancaire::factory()->create(['nom' => 'Compte tenant A']);

    $other = Association::factory()->create();
    TenantContext::boot($other);
    CompteBancaire::factory()->create(['nom' => 'Compte tenant B']);

    TenantContext::boot($this->association);

    $result = CompteBancaire::saisieManuelle()->get();

    expect($result->pluck('nom')->all())
        ->toContain('Compte tenant A')
        ->and($result->pluck('nom')->all())->not->toContain('Compte tenant B');
});
