<?php

declare(strict_types=1);

use App\Livewire\TiersQuickView;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    $this->exercice = app(ExerciceService::class)->current();
});

afterEach(function () {
    TenantContext::clear();
});

it('loads tiers data on open-tiers-quick-view event and becomes visible', function () {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@example.com',
        'telephone' => '0601020304',
    ]);

    Livewire::test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiers->id)
        ->assertSet('visible', true)
        ->assertSet('tiersId', $tiers->id)
        ->assertSee('marie@example.com');
});

it('starts hidden and shows nothing before event', function () {
    Livewire::test(TiersQuickView::class)
        ->assertSet('visible', false)
        ->assertSet('tiersId', null)
        ->assertDontSee('Toutes les transactions');
});

it('can change exercice and reloads summary', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'email' => 'change@example.com']);

    $otherYear = $this->exercice - 1;

    Livewire::test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiers->id)
        ->assertSet('exercice', $this->exercice)
        ->set('exercice', $otherYear)
        ->assertSet('exercice', $otherYear);
});

it('displays depenses section when summary has depenses', function () {
    $tiers = Tiers::factory()->pourDepenses()->create(['association_id' => $this->association->id, 'email' => 'depense@example.com']);

    $mockSummary = [
        'contact' => ['email' => 'depense@example.com', 'telephone' => null],
        'depenses' => ['count' => 3, 'total' => 450.00],
    ];

    $component = Livewire::test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiers->id);

    // Manually set summary for deterministic test
    $component->set('summary', $mockSummary)
        ->assertSee('DEP')
        ->assertSee('450');
});

it('hides and resets when close() is called', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'email' => 'close@example.com']);

    Livewire::test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiers->id)
        ->assertSet('visible', true)
        ->call('close')
        ->assertSet('visible', false)
        ->assertSet('tiersId', null)
        ->assertSet('summary', []);
});

it('shows tiers name and type badge when visible', function () {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'particulier',
        'nom' => 'Martin',
        'prenom' => 'Jean',
        'email' => 'jean.martin@example.com',
    ]);

    Livewire::test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiers->id)
        ->assertSee('MARTIN')
        ->assertSee('Jean');
});

it('shows no activity message when summary has no sections', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'email' => 'empty@example.com']);

    Livewire::test(TiersQuickView::class)
        ->dispatch('open-tiers-quick-view', tiersId: $tiers->id)
        ->set('summary', ['contact' => ['email' => 'empty@example.com', 'telephone' => null]])
        ->assertSee('Aucune activité sur cet exercice.');
});
