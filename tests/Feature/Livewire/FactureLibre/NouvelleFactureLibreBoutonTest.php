<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Livewire\FactureList;
use App\Models\Association;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->adminUser = User::factory()->create();
    $this->adminUser->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->adminUser->update(['derniere_association_id' => $this->association->id]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->adminUser);

    $this->exercice = app(ExerciceService::class)->current();
});

afterEach(function (): void {
    TenantContext::clear();
});

// ── Test 1 : Bouton présent ──────────────────────────────────────────────────

it('affiche le bouton Nouvelle facture libre sur FactureList', function (): void {
    Livewire::test(FactureList::class)
        ->assertSee('Nouvelle facture libre');
});

// ── Test 2 : Click ouvre la modale ──────────────────────────────────────────

it('ouvre la modale Nouvelle facture libre au click', function (): void {
    Livewire::test(FactureList::class)
        ->assertSet('showCreerLibreModal', false)
        ->call('ouvrirModalLibre')
        ->assertSet('showCreerLibreModal', true);
});

// ── Test 3 : Sélection tiers → création + redirection ────────────────────────

it('creerFactureLibre crée une facture brouillon sans devis et redirige vers la fiche', function (): void {
    $tiers = Tiers::factory()->pourRecettes()->create([
        'association_id' => $this->association->id,
    ]);

    Livewire::test(FactureList::class)
        ->call('creerFactureLibre', $tiers->id)
        ->assertRedirect();

    expect(
        Facture::where('tiers_id', $tiers->id)
            ->whereNull('devis_id')
            ->where('statut', StatutFacture::Brouillon)
            ->exists()
    )->toBeTrue();

    $facture = Facture::where('tiers_id', $tiers->id)->firstOrFail();
    Livewire::test(FactureList::class)
        ->call('creerFactureLibre', $tiers->id)
        ->assertRedirect(route('facturation.factures.show', Facture::where('tiers_id', $tiers->id)->latest('id')->firstOrFail()));
});

// ── Test 4 : Cross-tenant tiers → exception catchée, pas de facture ──────────

it('creerFactureLibre avec tiers cross-tenant affiche une erreur sans créer de facture', function (): void {
    $autreAssociation = Association::factory()->create();
    $tiersCrosstenant = Tiers::withoutGlobalScopes()->create([
        'association_id' => $autreAssociation->id,
        'type' => 'particulier',
        'nom' => 'Cross',
        'prenom' => 'Tenant',
        'pour_depenses' => false,
        'pour_recettes' => true,
        'est_helloasso' => false,
        'email_optout' => false,
    ]);

    Livewire::test(FactureList::class)
        ->call('creerFactureLibre', $tiersCrosstenant->id)
        ->assertNoRedirect()
        ->assertSeeHtml('interdit');

    expect(Facture::count())->toBe(0);
});

// ── Test 5 : Bouton classique "Nouvelle facture" inchangé ────────────────────

it('le bouton Nouvelle facture classique et son flow restent inchangés', function (): void {
    // Le composant doit toujours afficher le bouton classique
    Livewire::test(FactureList::class)
        ->assertSee('Nouvelle facture')
        ->assertSet('showCreerModal', false)
        ->call('creer')
        ->assertSet('showCreerModal', true)
        ->assertNoRedirect();
});
