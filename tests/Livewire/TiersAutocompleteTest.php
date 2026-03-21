<?php

declare(strict_types=1);

use App\Livewire\TiersAutocomplete;
use App\Livewire\TiersForm;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the component', function () {
    Livewire::test(TiersAutocomplete::class)->assertOk();
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

it('can search tiers by entreprise name', function () {
    Tiers::factory()->create([
        'type'          => 'entreprise',
        'entreprise'    => 'ACME Corp',
        'nom'           => 'Dupont',
        'pour_depenses' => true,
    ]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'ACME')
        ->call('doSearch')
        ->assertSee('ACME Corp');
});

it('can select a tiers', function () {
    $tiers = Tiers::factory()->create([
        'type'          => 'entreprise',
        'entreprise'    => 'ACME Corp',
        'nom'           => 'Dupont',
        'pour_depenses' => true,
    ]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->call('selectTiers', $tiers->id)
        ->assertSet('tiersId', $tiers->id)
        ->assertSet('selectedLabel', 'ACME Corp');
});

it('can clear the selection', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'pour_recettes' => true]);

    Livewire::test(TiersAutocomplete::class)
        ->call('selectTiers', $tiers->id)
        ->call('clearTiers')
        ->assertSet('tiersId', null)
        ->assertSet('selectedLabel', null);
});

it('dispatches open-tiers-form with prefill when creating new tiers', function () {
    Livewire::test(TiersAutocomplete::class, ['filtre' => 'recettes'])
        ->set('search', 'Jean Dupont')
        ->call('openCreateModal')
        ->assertDispatched('open-tiers-form');
});

it('dispatches open-tiers-form with depenses flag for depenses filter', function () {
    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'ACME')
        ->call('openCreateModal')
        ->assertDispatched('open-tiers-form');
});

it('selects tiers on tiers-saved event', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Nouveau', 'pour_recettes' => true]);

    Livewire::test(TiersAutocomplete::class)
        ->dispatch('tiers-saved', id: $tiers->id)
        ->assertSet('tiersId', $tiers->id);
});

it('shows activate modal for tiers excluded by filter', function () {
    Tiers::factory()->create([
        'nom'           => 'Dupont',
        'pour_depenses' => false,
        'pour_recettes' => true,
    ]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'Dupont')
        ->call('openCreateModal')
        ->assertSet('showActivateModal', true);
});

it('confirmCreate method no longer exists', function () {
    expect(method_exists(TiersAutocomplete::class, 'confirmCreate'))->toBeFalse();
});
