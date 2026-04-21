<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Livewire\Parametres\Comptabilite\UsagesComptables;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\UsagesComptablesService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->admin = User::factory()->create();
    AssociationUser::create([
        'user_id' => $this->admin->id,
        'association_id' => $this->asso->id,
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $this->catR = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Recette]);
    $this->catD = Categorie::factory()->for($this->asso, 'association')->create(['type' => TypeCategorie::Depense]);
    $this->actingAs($this->admin);
});

it('renders 4 usage cards', function () {
    Livewire::test(UsagesComptables::class)
        ->assertSee('Comptabilisation des indemnités kilométriques')
        ->assertSee('Comptabilisation des adhésions')
        ->assertSee('Comptabilisation des participations aux opérations')
        ->assertSee('Comptabilisation des Dons');
});

it('toggleDon persists through service', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    Livewire::test(UsagesComptables::class)
        ->call('toggleDon', $sc->id, true);
    expect($sc->fresh()->hasUsage(UsageComptable::Don))->toBeTrue();
});

it('setFraisKilometriques switches mono link', function () {
    $sc1 = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();
    $sc2 = SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create();
    Livewire::test(UsagesComptables::class)
        ->set('fraisKmSelectedId', $sc1->id)
        ->call('saveFraisKilometriques');
    expect($sc1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
    Livewire::test(UsagesComptables::class)
        ->set('fraisKmSelectedId', $sc2->id)
        ->call('saveFraisKilometriques');
    expect($sc1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
    expect($sc2->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
});

it('abandonCreanceCandidates lists only Dons', function () {
    $scDon = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create(['nom' => 'Don A']);
    $scAutre = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create(['nom' => 'Autre']);
    app(UsagesComptablesService::class)->toggleDon($scDon->id, true);

    $comp = Livewire::test(UsagesComptables::class);
    $candidates = collect($comp->instance()->abandonCreanceCandidates);
    expect($candidates->pluck('id'))->toContain($scDon->id);
    expect($candidates->pluck('id'))->not->toContain($scAutre->id);
});

it('toggleDon false cascades AbandonCreance', function () {
    $sc = SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create();
    $svc = app(UsagesComptablesService::class);
    $svc->toggleDon($sc->id, true);
    $svc->setAbandonCreance($sc->id);

    Livewire::test(UsagesComptables::class)->call('toggleDon', $sc->id, false);
    expect($sc->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeFalse();
});

it('submitInline creates sous-cat and flags it', function () {
    Livewire::test(UsagesComptables::class)
        ->set('inlineUsage', UsageComptable::Cotisation->value)
        ->set('inlineCategorieId', $this->catR->id)
        ->set('inlineNom', 'Nouvelle cotisation')
        ->set('inlineCodeCerfa', '751B')
        ->call('submitInline');
    $sc = SousCategorie::where('nom', 'Nouvelle cotisation')->first();
    expect($sc)->not->toBeNull();
    expect($sc->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('inlineCategoriesEligibles filtered to Depense for FraisKilometriques', function () {
    $comp = Livewire::test(UsagesComptables::class)
        ->set('inlineUsage', UsageComptable::FraisKilometriques->value);
    $cats = collect($comp->instance()->inlineCategoriesEligibles);
    $types = $cats->pluck('type')->unique()->values();
    expect($types->all())->toBe([TypeCategorie::Depense]);
});

it('denies non-admin users', function () {
    $otherUser = User::factory()->create();
    $this->actingAs($otherUser);
    Livewire::test(UsagesComptables::class)->assertForbidden();
});

it('route is reachable for admin', function () {
    session(['current_association_id' => $this->asso->id]);
    $this->get(route('parametres.comptabilite.usages'))->assertOk();
});

it('route denies non-admin', function () {
    $other = User::factory()->create();
    $other->associations()->attach($this->asso->id, ['role' => RoleAssociation::Consultation->value, 'joined_at' => now()]);
    $this->actingAs($other);
    session(['current_association_id' => $this->asso->id]);
    $this->get(route('parametres.comptabilite.usages'))->assertForbidden();
});
