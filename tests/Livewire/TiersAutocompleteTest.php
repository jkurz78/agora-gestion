<?php

declare(strict_types=1);

use App\Livewire\TiersAutocomplete;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the component', function () {
    Livewire::test(TiersAutocomplete::class)
        ->assertOk();
});

it('can search tiers by name', function () {
    Tiers::factory()->create(['nom' => 'Dupont', 'pour_depenses' => true]);
    Tiers::factory()->create(['nom' => 'Martin', 'pour_depenses' => true]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'Dup')
        ->assertSet('open', true)
        ->call('doSearch')
        ->assertSee('Dupont')
        ->assertDontSee('Martin');
});

it('can select a tiers', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'type' => 'entreprise', 'pour_depenses' => true]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->call('selectTiers', $tiers->id)
        ->assertSet('tiersId', $tiers->id)
        ->assertSet('selectedLabel', $tiers->nom);
});

it('can clear the selection', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont']);

    Livewire::test(TiersAutocomplete::class)
        ->call('selectTiers', $tiers->id)
        ->call('clearTiers')
        ->assertSet('tiersId', null)
        ->assertSet('selectedLabel', null);
});

it('can create a new tiers inline', function () {
    Livewire::test(TiersAutocomplete::class)
        ->set('newNom', 'Nouveau Tiers')
        ->set('newType', 'entreprise')
        ->call('confirmCreate')
        ->assertSet('tiersId', fn ($val) => $val !== null);

    expect(Tiers::where('nom', 'Nouveau Tiers')->exists())->toBeTrue();
});
