<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    Auth::guard('tiers-portail')->login($this->tiers);
});

// ---------------------------------------------------------------------------
// Test 1 : Tiers avec 0 NDF → message vide + bouton créer
// ---------------------------------------------------------------------------

it('index: tiers sans NDF voit le message vide et le bouton créer', function () {
    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSeeText('Aucune note de frais pour le moment.')
        ->assertSee('Nouvelle note de frais');
});

// ---------------------------------------------------------------------------
// Test 2 : 3 NDF du Tiers → 3 lignes dans le tableau
// ---------------------------------------------------------------------------

it('index: 3 NDF du tiers sont toutes affichées', function () {
    NoteDeFrais::factory()->count(3)->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais test',
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSee('Frais test');
});

// ---------------------------------------------------------------------------
// Test 3 : NDF d'un autre Tiers même asso → invisible
// ---------------------------------------------------------------------------

it('index: NDF d\'un autre tiers de la même asso est invisible', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
        'libelle' => 'NDF autre tiers',
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertDontSeeText('NDF autre tiers');
});

// ---------------------------------------------------------------------------
// Test 4 : Badge statut affiché correctement par statut
// ---------------------------------------------------------------------------

it('index: badge statut brouillon affiché', function () {
    NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSeeText('Brouillon');
});

it('index: badge statut soumise affiché', function () {
    NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSeeText('Soumise');
});

// ---------------------------------------------------------------------------
// Test 5 : Bouton "Modifier" uniquement sur brouillon, "Consulter" sinon
// ---------------------------------------------------------------------------

it('index: bouton Modifier visible sur brouillon', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSee("notes-de-frais/{$ndf->id}/edit")
        ->assertSeeText('Modifier');
});

it('index: bouton Modifier visible sur NDF soumise', function () {
    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSee("notes-de-frais/{$ndf->id}/edit")
        ->assertSeeText('Modifier');
});

it('index: bouton Consulter visible sur NDF validée', function () {
    $ndf = NoteDeFrais::factory()->validee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSeeText('Consulter');
});

// ---------------------------------------------------------------------------
// Test 6 : Total = sum des montants des lignes
// ---------------------------------------------------------------------------

it('index: total affiché est la somme des montants des lignes', function () {
    $ndf = NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    NoteDeFraisLigne::factory()->create(['note_de_frais_id' => $ndf->id, 'montant' => 25.00]);
    NoteDeFraisLigne::factory()->create(['note_de_frais_id' => $ndf->id, 'montant' => 15.50]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSee('40,50');
});

// ---------------------------------------------------------------------------
// Test 7 : Accès non-auth → redirect login
// ---------------------------------------------------------------------------

it('index: accès non authentifié redirige vers login', function () {
    Auth::guard('tiers-portail')->logout();

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertRedirect("/{$this->asso->slug}/portail/login");
});

// ---------------------------------------------------------------------------
// Test 8 : NDF avec plusieurs lignes n'est PAS dupliquée dans la liste
// ---------------------------------------------------------------------------

it('index: une NDF avec 2 lignes (standard + km) n\'apparaît qu\'une seule fois', function () {
    $ndf = NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF unique test duplication',
    ]);

    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'montant' => 25.00,
    ]);
    NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'type' => 'kilometrique',
        'sous_categorie_id' => null,
        'montant' => 50.00,
    ]);

    $html = $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->getContent();

    $occurrences = substr_count($html, 'NDF unique test duplication');
    expect($occurrences)->toBe(1);
});
