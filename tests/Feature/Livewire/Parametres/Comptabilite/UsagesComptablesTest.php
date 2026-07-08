<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Livewire\Parametres\Comptabilite\UsagesComptables;
use App\Models\Association;
use App\Models\AssociationUser;
use App\Models\Categorie;
use App\Models\Compte;
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

// DC-8 : l'écran liste des comptes — les fixtures créent la SousCategorie avec
// code_cerfa pour matérialiser le Compte miroir (observer DC-7), et les appels
// passent l'id du compte.

it('toggleDon persists through service', function () {
    SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create(['code_cerfa' => '754A']);
    $compte = Compte::where('numero_pcg', '754A')->firstOrFail();
    Livewire::test(UsagesComptables::class)
        ->call('toggleDon', $compte->id, true);
    expect($compte->fresh()->hasUsage(UsageComptable::Don))->toBeTrue();
});

it('setFraisKilometriques switches mono link', function () {
    SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create(['code_cerfa' => '625A']);
    SousCategorie::factory()->for($this->asso, 'association')->for($this->catD)->create(['code_cerfa' => '625B']);
    $compte1 = Compte::where('numero_pcg', '625A')->firstOrFail();
    $compte2 = Compte::where('numero_pcg', '625B')->firstOrFail();
    Livewire::test(UsagesComptables::class)
        ->set('fraisKmSelectedId', $compte1->id)
        ->call('saveFraisKilometriques');
    expect($compte1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
    Livewire::test(UsagesComptables::class)
        ->set('fraisKmSelectedId', $compte2->id)
        ->call('saveFraisKilometriques');
    expect($compte1->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeFalse();
    expect($compte2->fresh()->hasUsage(UsageComptable::FraisKilometriques))->toBeTrue();
});

it('abandonCreanceCandidates lists only Dons', function () {
    SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create(['nom' => 'Don A', 'code_cerfa' => '754A']);
    SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create(['nom' => 'Autre', 'code_cerfa' => '706A']);
    $compteDon = Compte::where('numero_pcg', '754A')->firstOrFail();
    $compteAutre = Compte::where('numero_pcg', '706A')->firstOrFail();
    app(UsagesComptablesService::class)->toggleDon($compteDon->id, true);

    $comp = Livewire::test(UsagesComptables::class);
    $candidates = collect($comp->instance()->abandonCreanceCandidates);
    expect($candidates->pluck('id'))->toContain($compteDon->id);
    expect($candidates->pluck('id'))->not->toContain($compteAutre->id);
});

it('toggleDon false cascades AbandonCreance', function () {
    SousCategorie::factory()->for($this->asso, 'association')->for($this->catR)->create(['code_cerfa' => '754A']);
    $compte = Compte::where('numero_pcg', '754A')->firstOrFail();
    $svc = app(UsagesComptablesService::class);
    $svc->toggleDon($compte->id, true);
    $svc->setAbandonCreance($compte->id);

    Livewire::test(UsagesComptables::class)->call('toggleDon', $compte->id, false);
    expect($compte->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeFalse();
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
