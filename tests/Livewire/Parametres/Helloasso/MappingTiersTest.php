<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Livewire\Parametres\Helloasso\MappingTiers;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoParametres;
use App\Models\HelloAssoTierMapping;
use App\Models\SousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
    $compte = CompteBancaire::factory()->create();
    $this->parametres = HelloAssoParametres::factory()->create([
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $compte->id,
        'compte_versement_id' => $compte->id,
        'sous_categorie_cotisation_id' => $this->sc->id,
        'sous_categorie_don_id' => $this->sc->id,
        'sous_categorie_inscription_id' => $this->sc->id,
    ]);
    $this->formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Adhésion adulte',
    ]);
});

it('affiche la liste des mappings existants', function (): void {
    HelloAssoTierMapping::factory()->create([
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 1,
        'helloasso_tier_label' => 'Adulte',
        'target_type' => FormuleAdhesion::class,
        'target_id' => $this->formule->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(MappingTiers::class)
        ->assertSee('cotisation-2025')
        ->assertSee('Adulte')
        ->assertSee('Adhésion adulte');
});

it('crée un mapping manuellement', function (): void {
    Livewire::actingAs($this->user)
        ->test(MappingTiers::class)
        ->set('newFormSlug', 'cotisation-2025')
        ->set('newTierId', 999)
        ->set('newTierLabel', 'Bienfaiteur')
        ->set('newFormuleId', $this->formule->id)
        ->call('create');

    expect(HelloAssoTierMapping::count())->toBe(1);
    $mapping = HelloAssoTierMapping::first();
    expect($mapping->helloasso_tier_id)->toBe(999);
    expect($mapping->helloasso_tier_label)->toBe('Bienfaiteur');
});

it('refuse un doublon (form_slug, tier_id) sur la même asso', function (): void {
    HelloAssoTierMapping::factory()->create([
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 999,
        'target_type' => FormuleAdhesion::class,
        'target_id' => $this->formule->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(MappingTiers::class)
        ->set('newFormSlug', 'cotisation-2025')
        ->set('newTierId', 999)
        ->set('newTierLabel', 'Doublon')
        ->set('newFormuleId', $this->formule->id)
        ->call('create')
        ->assertSee('existe déjà');

    expect(HelloAssoTierMapping::count())->toBe(1);
});

it('supprime un mapping', function (): void {
    $mapping = HelloAssoTierMapping::factory()->create([
        'target_type' => FormuleAdhesion::class,
        'target_id' => $this->formule->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(MappingTiers::class)
        ->call('delete', $mapping->id);

    expect(HelloAssoTierMapping::count())->toBe(0);
});

it('importe les tiers d\'un form HelloAsso et les pré-affiche', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-2025' => Http::response([
            'formSlug' => 'cotisation-2025',
            'formType' => 'Membership',
            'validityType' => 'MovingYear',
            'tiers' => [
                ['id' => 1, 'label' => 'Adulte', 'price' => 3000, 'isEligibleTaxReceipt' => false],
                ['id' => 2, 'label' => 'Étudiant', 'price' => 1500, 'isEligibleTaxReceipt' => false],
            ],
        ]),
    ]);

    Livewire::actingAs($this->user)
        ->test(MappingTiers::class)
        ->set('importFormType', 'Membership')
        ->set('importFormSlug', 'cotisation-2025')
        ->call('importerTiers')
        ->assertSee('Adulte')
        ->assertSee('Étudiant');
});
