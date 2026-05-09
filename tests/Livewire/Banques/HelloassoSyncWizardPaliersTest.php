<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Livewire\Banques\HelloassoSyncWizard;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\HelloAssoTierMapping;
use App\Models\SousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    // HelloassoSyncWizard est hardcodé sur association_id=1.
    // On force l'asso à id=1 comme dans HelloAssoFormTest.
    $this->association = Association::firstOrCreate(
        ['id' => 1],
        ['nom' => 'Mon Asso', 'slug' => 'mon-asso']
    );
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    $this->sc = SousCategorie::factory()->pourCotisations()->create();
    $compte = CompteBancaire::factory()->create();
    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => 1,
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
    $this->formMembership = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-2025',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025',
    ]);
    $this->formEvent = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'event-2025',
        'form_type' => 'Event',
        'form_title' => 'Event 2025',
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('chargerPaliers fetch les paliers pour un form Membership et pré-sélectionne les mappings existants', function (): void {
    Http::fake([
        '*api.helloasso-sandbox.com/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*api.helloasso-sandbox.com/v5/organizations/mon-asso/forms/Membership/cotisation-2025' => Http::response([
            'formSlug' => 'cotisation-2025',
            'formType' => 'Membership',
            'tiers' => [
                ['id' => 1, 'label' => 'Adulte', 'price' => 3000],
                ['id' => 2, 'label' => 'Étudiant', 'price' => 1500],
            ],
        ]),
    ]);

    HelloAssoTierMapping::create([
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 1,
        'helloasso_tier_label' => 'Adulte',
        'target_type' => FormuleAdhesion::class,
        'target_id' => $this->formule->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->call('chargerPaliers', $this->formMembership->id)
        ->assertSet('showPaliersFor', $this->formMembership->id)
        ->assertSet('paliersErreur', null)
        ->assertSet("paliersForms.{$this->formMembership->id}.0.label", 'Adulte')
        ->assertSet("paliersFormulesMap.{$this->formMembership->id}.1", $this->formule->id)
        ->assertSet("paliersFormulesMap.{$this->formMembership->id}.2", null);
});

it('chargerPaliers refuse les forms non Membership', function (): void {
    Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->call('chargerPaliers', $this->formEvent->id)
        ->assertSet('paliersErreur', 'Le mapping de paliers n\'est disponible que pour les formulaires de type Membership.');
});

it('sauvegarderPalierMapping crée puis met à jour un mapping', function (): void {
    Http::fake([
        '*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class);

    // Pré-charger les paliers manuellement (skip API)
    $component->set('paliersForms', [
        $this->formMembership->id => [
            ['id' => 1, 'label' => 'Adulte', 'price' => 3000],
        ],
    ]);

    // Premier appel : create
    $component->call('sauvegarderPalierMapping', $this->formMembership->id, 1, $this->formule->id);

    expect(HelloAssoTierMapping::count())->toBe(1);
    $mapping = HelloAssoTierMapping::first();
    expect($mapping->helloasso_form_slug)->toBe('cotisation-2025');
    expect($mapping->helloasso_tier_id)->toBe(1);
    expect($mapping->target_id)->toBe($this->formule->id);

    // Second appel avec une nouvelle formule : update
    $autreFormule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Adhésion bienfaiteur',
        'actif' => false,
    ]);
    $component->call('sauvegarderPalierMapping', $this->formMembership->id, 1, $autreFormule->id);

    expect(HelloAssoTierMapping::count())->toBe(1);
    expect(HelloAssoTierMapping::first()->target_id)->toBe($autreFormule->id);
});

it('sauvegarderPalierMapping avec formuleId=null supprime le mapping', function (): void {
    HelloAssoTierMapping::create([
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 1,
        'helloasso_tier_label' => 'Adulte',
        'target_type' => FormuleAdhesion::class,
        'target_id' => $this->formule->id,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class);
    $component->set('paliersForms', [
        $this->formMembership->id => [
            ['id' => 1, 'label' => 'Adulte', 'price' => 3000],
        ],
    ]);

    $component->call('sauvegarderPalierMapping', $this->formMembership->id, 1, null);

    expect(HelloAssoTierMapping::count())->toBe(0);
});

it('fermerPaliers réinitialise showPaliersFor', function (): void {
    Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->set('showPaliersFor', $this->formMembership->id)
        ->call('fermerPaliers')
        ->assertSet('showPaliersFor', null);
});
