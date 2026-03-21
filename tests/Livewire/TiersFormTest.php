<?php

// tests/Livewire/TiersFormTest.php
declare(strict_types=1);

use App\Livewire\TiersForm;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

// --- Création particulier ---

it('can create a particulier with minimal fields', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'particulier')
        ->set('nom', 'Dupont')
        ->set('pour_recettes', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    $this->assertDatabaseHas('tiers', ['nom' => 'Dupont', 'type' => 'particulier']);
});

it('dispatches tiers-saved with the created tiers id', function () {
    $component = Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Durand')
        ->set('pour_recettes', true)
        ->call('save');

    $tiers = Tiers::where('nom', 'Durand')->firstOrFail();
    $component->assertDispatched('tiers-saved', id: $tiers->id);
});

it('can create a particulier with all fields', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'particulier')
        ->set('nom', 'Martin')
        ->set('prenom', 'Jean')
        ->set('email', 'jean@example.fr')
        ->set('telephone', '06 12 34 56 78')
        ->set('adresse_ligne1', '5 rue du Port')
        ->set('code_postal', '78500')
        ->set('ville', 'Sartrouville')
        ->set('pays', 'France')
        ->set('date_naissance', '1980-06-15')
        ->set('pour_recettes', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tiers', [
        'nom'         => 'Martin',
        'prenom'      => 'Jean',
        'code_postal' => '78500',
        'ville'       => 'Sartrouville',
    ]);

    $tiers = Tiers::where('nom', 'Martin')->firstOrFail();
    expect($tiers->date_naissance->format('Y-m-d'))->toBe('1980-06-15');
});

// --- Création entreprise ---

it('can create an entreprise with minimal fields', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'entreprise')
        ->set('entreprise', 'ACME Corp')
        ->set('pour_depenses', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    $this->assertDatabaseHas('tiers', ['entreprise' => 'ACME Corp', 'type' => 'entreprise']);
});

it('requires entreprise field when type is entreprise', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'entreprise')
        ->set('entreprise', '')
        ->set('pour_depenses', true)
        ->call('save')
        ->assertHasErrors(['entreprise']);
});

// --- Switch radio ---

it('switch to entreprise concatenates prenom and nom into entreprise field', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Martin')
        ->set('prenom', 'Jean')
        ->set('type', 'entreprise')
        ->assertSet('entreprise', 'Jean Martin')
        ->assertSet('nom', '')
        ->assertSet('prenom', null);
});

it('switch to entreprise with only nom fills entreprise field', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Dupont')
        ->set('type', 'entreprise')
        ->assertSet('entreprise', 'Dupont')
        ->assertSet('nom', '');
});

// --- Validation ---

it('validates nom required for particulier', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'particulier')
        ->set('nom', '')
        ->set('pour_recettes', true)
        ->call('save')
        ->assertHasErrors(['nom']);
});

it('validates at least one usage flag', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Test')
        ->set('pour_depenses', false)
        ->set('pour_recettes', false)
        ->call('save')
        ->assertHasErrors(['pour_depenses']);
});

// --- Édition ---

it('loads existing tiers for editing', function () {
    $tiers = Tiers::factory()->create([
        'nom'        => 'Leclerc',
        'type'       => 'entreprise',
        'entreprise' => 'Leclerc SA',
        'ville'      => 'Bordeaux',
    ]);

    Livewire::test(TiersForm::class)
        ->dispatch('edit-tiers', id: $tiers->id)
        ->assertSet('nom', 'Leclerc')
        ->assertSet('entreprise', 'Leclerc SA')
        ->assertSet('ville', 'Bordeaux')
        ->assertSet('showDetails', true);
});

it('details section is closed on new form', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->assertSet('showDetails', false);
});

it('can update a tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Ancien', 'pour_depenses' => true]);

    Livewire::test(TiersForm::class)
        ->dispatch('edit-tiers', id: $tiers->id)
        ->set('nom', 'Nouveau')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    expect($tiers->fresh()->nom)->toBe('Nouveau');
});

// --- Listener open-tiers-form ---

it('opens with prefill from open-tiers-form event', function () {
    Livewire::test(TiersForm::class)
        ->dispatch('open-tiers-form', prefill: [
            'nom'           => 'Jean Dupont',
            'pour_recettes' => true,
            'pour_depenses' => false,
        ])
        ->assertSet('showForm', true)
        ->assertSet('nom', 'Jean Dupont')
        ->assertSet('type', 'particulier')
        ->assertSet('pour_recettes', true)
        ->assertSet('pour_depenses', false);
});
