<?php

declare(strict_types=1);

use App\Livewire\TiersMergeModal;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('opens modal and loads tiers data on open-tiers-merge event', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@example.com',
        'telephone' => '0601020304',
        'adresse_ligne1' => '10 rue de Paris',
        'code_postal' => '75001',
        'ville' => 'Paris',
        'pays' => 'France',
    ]);

    $sourceData = [
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'new@example.com',
        'telephone' => '',
        'adresse_ligne1' => '20 avenue Victor Hugo',
        'code_postal' => '69001',
        'ville' => 'Lyon',
        'pays' => 'France',
    ];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Données HelloAsso',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer ce tiers',
            context: 'helloasso',
            contextData: ['index' => 0],
        )
        ->assertSet('showModal', true)
        ->assertSet('sourceData.email', 'new@example.com')
        ->assertSet('targetData.email', 'marie@example.com')
        ->assertSet('resultData.email', 'marie@example.com') // target has value, keep it
        ->assertSet('resultData.adresse_ligne1', '10 rue de Paris'); // target has value, keep it
});

it('pre-fills result with source values when target fields are empty', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => null,
        'telephone' => null,
        'adresse_ligne1' => null,
        'code_postal' => null,
        'ville' => null,
        'pays' => 'France',
    ]);

    $sourceData = [
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@new.com',
        'telephone' => '0612345678',
        'adresse_ligne1' => '5 rue Neuve',
        'code_postal' => '31000',
        'ville' => 'Toulouse',
        'pays' => 'France',
    ];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'test',
        )
        ->assertSet('resultData.nom', 'Dupont')        // target had value
        ->assertSet('resultData.email', 'marie@new.com') // target was empty, took source
        ->assertSet('resultData.telephone', '0612345678') // target was empty, took source
        ->assertSet('resultData.ville', 'Toulouse');      // target was empty, took source
});

it('always keeps target type over source type', function () {
    $tiers = Tiers::factory()->create(['type' => 'entreprise', 'nom' => 'ACME']);

    $sourceData = ['type' => 'particulier', 'nom' => 'Dupont'];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'test',
        )
        ->assertSet('resultData.type', 'entreprise');
});
