<?php

// tests/Livewire/TiersFormTest.php
declare(strict_types=1);

use App\Livewire\TiersForm;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('can create a new tiers', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'entreprise')
        ->set('nom', 'Mairie de Lyon')
        ->set('pour_depenses', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    $this->assertDatabaseHas('tiers', ['nom' => 'Mairie de Lyon', 'pour_depenses' => true]);
});

it('validates nom is required', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', '')
        ->call('save')
        ->assertHasErrors(['nom']);
});

it('validates at least one flag is checked', function () {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('nom', 'Test')
        ->set('pour_depenses', false)
        ->set('pour_recettes', false)
        ->call('save')
        ->assertHasErrors(['pour_depenses']);
});

it('can load existing tiers for editing', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Leclerc SA', 'type' => 'entreprise']);

    Livewire::test(TiersForm::class)
        ->dispatch('edit-tiers', id: $tiers->id)
        ->assertSet('nom', 'Leclerc SA')
        ->assertSet('type', 'entreprise');
});

it('can update a tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Ancien nom', 'pour_depenses' => true]);

    Livewire::test(TiersForm::class)
        ->dispatch('edit-tiers', id: $tiers->id)
        ->set('nom', 'Nouveau nom')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('tiers-saved');

    expect($tiers->fresh()->nom)->toBe('Nouveau nom');
});
