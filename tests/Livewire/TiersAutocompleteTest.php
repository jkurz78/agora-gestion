<?php

declare(strict_types=1);

use App\Livewire\TiersAutocomplete;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('renders the component', function () {
    Livewire::test(TiersAutocomplete::class)->assertOk();
});

it('can search tiers by name', function () {
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'pour_depenses' => true]);
    Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Martin', 'pour_depenses' => true]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'Dup')
        ->assertSet('open', true)
        ->call('doSearch')
        ->assertSee('DUPONT')
        ->assertDontSee('MARTIN');
});

it('can search tiers by entreprise name', function () {
    Tiers::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'entreprise',
        'entreprise' => 'ACME Corp',
        'nom' => 'Dupont',
        'pour_depenses' => true,
    ]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'ACME')
        ->call('doSearch')
        ->assertSee('ACME Corp');
});

it('can select a tiers', function () {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'entreprise',
        'entreprise' => 'ACME Corp',
        'nom' => 'Dupont',
        'pour_depenses' => true,
    ]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->call('selectTiers', $tiers->id)
        ->assertSet('tiersId', $tiers->id)
        ->assertSet('selectedLabel', 'ACME Corp');
});

it('can clear the selection', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'pour_recettes' => true]);

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
        ->assertDispatched('open-tiers-form', fn ($eventName, $eventParams) => ($eventParams['prefill']['nom'] ?? null) === 'Jean Dupont' &&
            ($eventParams['prefill']['pour_recettes'] ?? null) === true &&
            ($eventParams['prefill']['pour_depenses'] ?? null) === false
        );
});

it('dispatches open-tiers-form with depenses flag for depenses filter', function () {
    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'ACME')
        ->call('openCreateModal')
        ->assertDispatched('open-tiers-form', fn ($eventName, $eventParams) => ($eventParams['prefill']['nom'] ?? null) === 'ACME' &&
            ($eventParams['prefill']['pour_recettes'] ?? null) === false &&
            ($eventParams['prefill']['pour_depenses'] ?? null) === true
        );
});

it('selects tiers on tiers-saved event', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Nouveau', 'pour_recettes' => true]);

    Livewire::test(TiersAutocomplete::class)
        ->dispatch('tiers-saved', id: $tiers->id)
        ->assertSet('tiersId', $tiers->id);
});

it('shows activate modal for tiers excluded by filter', function () {
    Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Dupont',
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
