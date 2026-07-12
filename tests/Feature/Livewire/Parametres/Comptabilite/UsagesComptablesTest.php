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

// DC-10a : l'écran liste des comptes — les fixtures créent le Compte directement,
// et les appels passent l'id du compte.

it('toggleDon persists through service', function () {
    $compte = Compte::factory()->numero('754')->create();
    Livewire::test(UsagesComptables::class)
        ->call('toggleDon', $compte->id, true);
    expect($compte->fresh()->hasUsage(UsageComptable::Don))->toBeTrue();
});

it('setFraisKilometriques switches mono link', function () {
    $compte1 = Compte::factory()->depense()->numero('625')->create();
    $compte2 = Compte::factory()->depense()->numero('6250')->create();
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
    $compteDon = Compte::factory()->numero('754')->create(['intitule' => 'Don A']);
    $compteAutre = Compte::factory()->numero('706')->create(['intitule' => 'Autre']);
    app(UsagesComptablesService::class)->toggleDon($compteDon->id, true);

    $comp = Livewire::test(UsagesComptables::class);
    $candidates = collect($comp->instance()->abandonCreanceCandidates);
    expect($candidates->pluck('id'))->toContain($compteDon->id);
    expect($candidates->pluck('id'))->not->toContain($compteAutre->id);
});

it('toggleDon false cascades AbandonCreance', function () {
    $compte = Compte::factory()->numero('754')->create();
    $svc = app(UsagesComptablesService::class);
    $svc->toggleDon($compte->id, true);
    $svc->setAbandonCreance($compte->id);

    Livewire::test(UsagesComptables::class)->call('toggleDon', $compte->id, false);
    expect($compte->fresh()->hasUsage(UsageComptable::AbandonCreance))->toBeFalse();
});

it('submitInline creates compte and flags it', function () {
    Livewire::test(UsagesComptables::class)
        ->set('inlineUsage', UsageComptable::Cotisation->value)
        ->set('inlineCategorieId', $this->catR->id)
        ->set('inlineNom', 'Nouvelle cotisation')
        ->set('inlineCodeCerfa', '751B')
        ->call('submitInline');
    $compte = Compte::where('numero_pcg', '751B')->first();
    expect($compte)->not->toBeNull();
    expect($compte->hasUsage(UsageComptable::Cotisation))->toBeTrue();
});

it('submitInline surfaces a classe mismatch as a validation error', function () {
    // Cotisation est une Recette (classe 7 attendue) — '606' est classe 6.
    Livewire::test(UsagesComptables::class)
        ->set('inlineUsage', UsageComptable::Cotisation->value)
        ->set('inlineCategorieId', $this->catR->id)
        ->set('inlineNom', 'Compte invalide')
        ->set('inlineCodeCerfa', '606')
        ->call('submitInline')
        ->assertHasErrors(['inlineCodeCerfa']);
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
