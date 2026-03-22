<?php

// tests/Unit/Models/TiersTest.php
declare(strict_types=1);

use App\Models\Tiers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

it('displayName returns nom as fallback when entreprise field is null', function () {
    $tiers = new Tiers(['type' => 'entreprise', 'entreprise' => null, 'nom' => 'Mairie de Lyon', 'prenom' => null]);
    expect($tiers->displayName())->toBe('Mairie de Lyon');
});

it('displayName returns prenom nom for particulier', function () {
    $tiers = new Tiers(['type' => 'particulier', 'nom' => 'Martin', 'prenom' => 'Jean']);
    expect($tiers->displayName())->toBe('Jean Martin');
});

it('displayName works with no prenom for particulier', function () {
    $tiers = new Tiers(['type' => 'particulier', 'nom' => 'Martin', 'prenom' => null]);
    expect($tiers->displayName())->toBe('Martin');
});

it('factory creates a valid tiers', function () {
    $tiers = Tiers::factory()->create();
    expect($tiers->nom)->not->toBeEmpty();
    expect($tiers->type)->toBeIn(['entreprise', 'particulier']);
});

it('pourDepenses state sets pour_depenses to true', function () {
    $tiers = Tiers::factory()->pourDepenses()->make();
    expect($tiers->pour_depenses)->toBeTrue();
});

it('pourRecettes state sets pour_recettes to true', function () {
    $tiers = Tiers::factory()->pourRecettes()->make();
    expect($tiers->pour_recettes)->toBeTrue();
});

it('tiers table has new columns after migration', function () {
    expect(Schema::hasColumn('tiers', 'adresse_ligne1'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'adresse'))->toBeFalse();
    expect(Schema::hasColumn('tiers', 'code_postal'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'ville'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'pays'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'entreprise'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'date_naissance'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'est_helloasso'))->toBeTrue();
    expect(Schema::hasColumn('tiers', 'helloasso_id'))->toBeFalse();
});

it('est_helloasso defaults to false', function () {
    $tiers = Tiers::factory()->create(['pour_depenses' => true]);
    expect($tiers->est_helloasso)->toBeFalse();
});

it('est_helloasso can be set to true', function () {
    $tiers = Tiers::factory()->avecHelloasso()->create(['pour_depenses' => true]);
    expect($tiers->est_helloasso)->toBeTrue();
});

it('displayName returns entreprise field for entreprise type', function () {
    $tiers = new Tiers(['type' => 'entreprise', 'entreprise' => 'ACME Corp', 'nom' => 'Dupont']);
    expect($tiers->displayName())->toBe('ACME Corp');
});

it('displayName falls back to nom when entreprise field is null', function () {
    $tiers = new Tiers(['type' => 'entreprise', 'entreprise' => null, 'nom' => 'Mairie de Lyon']);
    expect($tiers->displayName())->toBe('Mairie de Lyon');
});

it('can create tiers with all new fields', function () {
    $tiers = Tiers::factory()->create([
        'entreprise' => 'ACME Corp',
        'code_postal' => '75001',
        'ville' => 'Paris',
        'pays' => 'France',
        'date_naissance' => '1990-05-15',
        'est_helloasso' => true,
        'pour_depenses' => true,
    ]);
    expect($tiers->entreprise)->toBe('ACME Corp');
    expect($tiers->code_postal)->toBe('75001');
    expect($tiers->pays)->toBe('France');
    expect($tiers->est_helloasso)->toBeTrue();
    expect($tiers->date_naissance)->toBeInstanceOf(Carbon::class);
});
