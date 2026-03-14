<?php

// tests/Unit/Models/TiersTest.php
declare(strict_types=1);

use App\Models\Tiers;

it('displayName returns nom for entreprise', function () {
    $tiers = new Tiers(['type' => 'entreprise', 'nom' => 'Mairie de Lyon', 'prenom' => null]);
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
