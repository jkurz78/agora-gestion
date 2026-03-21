<?php

// tests/Feature/Services/TiersServiceTest.php
declare(strict_types=1);

use App\Models\Tiers;
use App\Services\TiersService;

it('crée un tiers', function () {
    $tiers = app(TiersService::class)->create([
        'type' => 'entreprise',
        'nom' => 'Mairie de Lyon',
        'prenom' => null,
        'email' => 'contact@mairie.fr',
        'telephone' => null,
        'adresse_ligne1' => null,
        'pour_depenses' => true,
        'pour_recettes' => false,
    ]);

    expect($tiers)->toBeInstanceOf(Tiers::class);
    expect($tiers->nom)->toBe('Mairie de Lyon');
    $this->assertDatabaseHas('tiers', ['nom' => 'Mairie de Lyon', 'pour_depenses' => true]);
});

it('met à jour un tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Ancien nom']);

    app(TiersService::class)->update($tiers, ['nom' => 'Nouveau nom']);

    expect($tiers->fresh()->nom)->toBe('Nouveau nom');
});

it('supprime un tiers sans contrainte', function () {
    $tiers = Tiers::factory()->create();

    app(TiersService::class)->delete($tiers);

    $this->assertDatabaseMissing('tiers', ['id' => $tiers->id]);
});
