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

it('updates tiers with result data on confirmMerge', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'old@example.com',
        'pour_depenses' => false,
        'pour_recettes' => true,
        'est_helloasso' => false,
    ]);

    $sourceData = [
        'nom' => 'Durand',
        'prenom' => 'Jean',
        'email' => 'new@example.com',
        'pour_recettes' => false,
        'est_helloasso' => true,
    ];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'helloasso',
            contextData: ['index' => 0],
        )
        ->set('resultData.nom', 'Durand')
        ->set('resultData.email', 'new@example.com')
        ->call('confirmMerge')
        ->assertDispatched('tiers-merge-confirmed', fn ($name, $params) =>
            $params['tiersId'] === $tiers->id
            && $params['context'] === 'helloasso'
            && $params['contextData'] === ['index' => 0]
        )
        ->assertSet('showModal', false);

    $tiers->refresh();
    expect($tiers->nom)->toBe('Durand');
    expect($tiers->email)->toBe('new@example.com');
    // OR logic on booleans
    expect($tiers->pour_recettes)->toBeTrue();   // was true, stays true
    expect($tiers->est_helloasso)->toBeTrue();    // OR: false || true = true
});

it('dispatches tiers-merge-cancelled on cancel without DB changes', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'email' => 'old@test.com']);

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: ['nom' => 'Autre', 'email' => 'new@test.com'],
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'medecin',
        )
        ->call('cancelMerge')
        ->assertDispatched('tiers-merge-cancelled', fn ($name, $params) =>
            $params['context'] === 'medecin'
        )
        ->assertSet('showModal', false);

    $tiers->refresh();
    expect($tiers->nom)->toBe('Dupont');
    expect($tiers->email)->toBe('old@test.com');
});

it('blocks confirmMerge when HelloAsso identities conflict', function () {
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'est_helloasso' => true,
        'helloasso_nom' => 'Dupont',
        'helloasso_prenom' => 'Marie',
    ]);

    $sourceData = [
        'nom' => 'Dupont',
        'est_helloasso' => true,
        'helloasso_nom' => 'Dupont',
        'helloasso_prenom' => 'Jean',
    ];

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Fusionner',
            context: 'fusion',
        )
        ->assertSet('helloassoIdConflict', true)
        ->call('confirmMerge')
        ->assertNotDispatched('tiers-merge-confirmed');

    $tiers->refresh();
    expect($tiers->nom)->toBe('Dupont'); // unchanged
});
