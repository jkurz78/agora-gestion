<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoSyncConfig;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->associationA = Association::factory()->create();
    $this->associationB = Association::factory()->create();

    TenantContext::boot($this->associationA);
    $this->compteA = CompteBancaire::factory()->create([
        'association_id' => (int) $this->associationA->id,
        'nom' => 'Compte HA tenant A',
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => true,
    ]);
    $this->parametresA = HelloAssoParametres::create([
        'association_id' => (int) $this->associationA->id,
        'client_id' => 'client-a',
        'client_secret' => 'secret-a',
        'organisation_slug' => 'asso-a',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => (int) $this->compteA->id,
    ]);

    TenantContext::clear();
    TenantContext::boot($this->associationB);
    session(['current_association_id' => (int) $this->associationB->id]);
    $this->compteB = CompteBancaire::factory()->create([
        'association_id' => (int) $this->associationB->id,
        'nom' => 'Compte HA tenant B',
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => true,
    ]);
    $this->parametresB = HelloAssoParametres::create([
        'association_id' => (int) $this->associationB->id,
        'client_id' => 'client-b',
        'client_secret' => 'secret-b',
        'organisation_slug' => 'asso-b',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => (int) $this->compteB->id,
    ]);

    $this->user = User::factory()->create();
    $this->user->associations()->attach((int) $this->associationB->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('renders only the current tenant configuration outside association 1', function (): void {
    expect((int) $this->associationB->id)->not->toBe(1);

    $component = Livewire::test(HelloassoSyncConfig::class)
        ->assertSet('compteHelloassoId', (int) $this->compteB->id)
        ->assertStatus(200);

    expect($component->viewData('comptesHelloasso')->pluck('id')->map(fn ($id): int => (int) $id)->all())
        ->toBe([(int) $this->compteB->id]);
});

it('saves sync config only for the current tenant', function (): void {
    $nouveauCompteB = CompteBancaire::factory()->create([
        'association_id' => (int) $this->associationB->id,
        'nom' => 'Nouveau compte HA tenant B',
    ]);

    Livewire::test(HelloassoSyncConfig::class)
        ->set('compteHelloassoId', (int) $nouveauCompteB->id)
        ->call('sauvegarder')
        ->assertHasNoErrors();

    expect((int) HelloAssoParametres::query()->findOrFail((int) $this->parametresB->id)->compte_helloasso_id)
        ->toBe((int) $nouveauCompteB->id);
    expect((int) HelloAssoParametres::withoutGlobalScopes()->findOrFail((int) $this->parametresA->id)->compte_helloasso_id)
        ->toBe((int) $this->compteA->id);
});

it('refuse les comptes bancaires et le compte de don d’un autre tenant', function (): void {
    $compteDonA = Compte::factory()->numero('7541')->create([
        'association_id' => (int) $this->associationA->id,
    ]);

    Livewire::test(HelloassoSyncConfig::class)
        ->set('compteHelloassoId', (int) $this->compteA->id)
        ->set('compteVersementId', (int) $this->compteA->id)
        ->set('compteDonId', (int) $compteDonA->id)
        ->call('sauvegarder')
        ->assertHasErrors(['compteHelloassoId', 'compteVersementId', 'compteDonId']);

    $parametresB = HelloAssoParametres::query()->findOrFail((int) $this->parametresB->id);
    expect((int) $parametresB->compte_helloasso_id)->toBe((int) $this->compteB->id)
        ->and($parametresB->compte_versement_id)->toBeNull()
        ->and($parametresB->compte_don_id)->toBeNull();
});
