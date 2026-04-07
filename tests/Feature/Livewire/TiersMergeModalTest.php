<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Livewire\Banques\HelloassoSyncWizard;
use App\Livewire\ParticipantShow;
use App\Livewire\TiersMergeModal;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\TypeOperation;
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
        ->assertSet('resultData.nom', 'DUPONT')        // target had value (accessor uppercases)
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
        ->assertDispatched('tiers-merge-confirmed', fn ($name, $params) => $params['tiersId'] === $tiers->id
            && $params['context'] === 'helloasso'
            && $params['contextData'] === ['index' => 0]
        )
        ->assertSet('showModal', false);

    $tiers->refresh();
    expect($tiers->nom)->toBe('DURAND');
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
        ->assertDispatched('tiers-merge-cancelled', fn ($name, $params) => $params['context'] === 'medecin'
        )
        ->assertSet('showModal', false);

    $tiers->refresh();
    expect($tiers->nom)->toBe('DUPONT');
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
    expect($tiers->nom)->toBe('DUPONT'); // unchanged (accessor uppercases)
});

it('renders modal with field labels and column headers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: ['nom' => 'Durand', 'prenom' => 'Jean'],
            tiersId: $tiers->id,
            sourceLabel: 'Données HelloAsso',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer ce tiers',
            context: 'test',
        )
        ->assertSee('Données HelloAsso')
        ->assertSee('Tiers existant')
        ->assertSee('Résultat')
        ->assertSee('Associer ce tiers')
        ->assertSee('DUPONT')
        ->assertSee('Durand');
});

it('HelloassoSyncWizard associerTiers dispatches open-tiers-merge', function () {
    view()->share('espace', Espace::Gestion);

    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'pour_recettes' => true]);

    $component = Livewire::test(HelloassoSyncWizard::class);

    // Simulate state that would exist after loadTiers()
    $component->set('persons', [
        ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com',
            'address' => '5 rue X', 'city' => 'Lyon', 'zipCode' => '69001', 'country' => 'France',
            'tiers_id' => null, 'tiers_name' => null],
    ]);
    $component->set('selectedTiers', [0 => $tiers->id]);

    $component->call('associerTiers', 0)
        ->assertDispatched('open-tiers-merge');

    // Tiers should NOT be updated yet (no direct update)
    $tiers->refresh();
    expect($tiers->est_helloasso)->toBeFalse();
});

it('ParticipantShow mapMedecinTiers dispatches open-tiers-merge', function () {
    $typeOp = TypeOperation::factory()->create([
        'formulaire_parcours_therapeutique' => true,
        'formulaire_prescripteur' => true,
    ]);
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $tiers = Tiers::factory()->create(['nom' => 'Participant']);
    $medecinTiers = Tiers::factory()->create(['nom' => 'DrMedecin', 'prenom' => 'Paul']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'medecin_nom' => 'Martin',
        'medecin_prenom' => 'Sophie',
        'medecin_telephone' => '0601020304',
        'medecin_email' => 'sophie@doc.fr',
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $operation,
        'participant' => $participant,
    ])
        ->set('mapMedecinTiersId', $medecinTiers->id)
        ->call('mapMedecinTiers')
        ->assertDispatched('open-tiers-merge');

    // Participant should NOT have medecin_tiers_id yet
    $participant->refresh();
    expect($participant->medecin_tiers_id)->toBeNull();
});

it('dispatches tiers-merge-create-new with correct data on createNewTiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: ['nom' => 'Durand', 'prenom' => 'Jean', 'email' => 'jean@test.com'],
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'helloasso',
            contextData: ['index' => 3, 'person' => ['firstName' => 'Jean', 'lastName' => 'Durand']],
        )
        ->call('createNewTiers')
        ->assertDispatched('tiers-merge-create-new', fn ($name, $params) => $params['context'] === 'helloasso'
            && $params['contextData'] === ['index' => 3, 'person' => ['firstName' => 'Jean', 'lastName' => 'Durand']]
            && isset($params['sourceData']['nom'])
        );
});

it('closes modal after createNewTiers is called', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: ['nom' => 'Durand', 'prenom' => 'Jean'],
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'test',
        )
        ->assertSet('showModal', true)
        ->call('createNewTiers')
        ->assertSet('showModal', false)
        ->assertSet('tiersId', null)
        ->assertSet('sourceData', [])
        ->assertSet('targetData', [])
        ->assertSet('resultData', []);
});

it('confirmMerge still works correctly after adding createNewTiers', function () {
    $tiers = Tiers::factory()->create([
        'type' => 'particulier',
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'old@example.com',
    ]);

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: ['nom' => 'Durand', 'prenom' => 'Jean', 'email' => 'new@example.com'],
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'helloasso',
            contextData: ['index' => 0],
        )
        ->call('confirmMerge')
        ->assertDispatched('tiers-merge-confirmed')
        ->assertSet('showModal', false);
});

it('cancelMerge still works correctly after adding createNewTiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont']);

    Livewire::test(TiersMergeModal::class)
        ->dispatch('open-tiers-merge',
            sourceData: ['nom' => 'Autre'],
            tiersId: $tiers->id,
            sourceLabel: 'Source',
            targetLabel: 'Cible',
            confirmLabel: 'Valider',
            context: 'test',
        )
        ->call('cancelMerge')
        ->assertDispatched('tiers-merge-cancelled')
        ->assertSet('showModal', false);
});
