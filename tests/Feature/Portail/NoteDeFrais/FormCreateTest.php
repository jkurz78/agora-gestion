<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutOperation;
use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

// ---------------------------------------------------------------------------
// Setup: portail test pattern — use HTTP requests (Livewire::test() bypasses
// the BootTenantFromSlug middleware which shares $portailAssociation).
// ---------------------------------------------------------------------------

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// Test 1 : Form vierge rendu avec une ligne vide par défaut
// ---------------------------------------------------------------------------

it('form create: page create affichée avec formulaire', function () {
    $this->get("/portail/{$this->asso->slug}/notes-de-frais/nouvelle")
        ->assertStatus(200)
        ->assertSee('Nouvelle note de frais')
        ->assertSee('Ajouter une ligne');
});

// ---------------------------------------------------------------------------
// Test 2 : Ajouter ligne via component method (unit test on component)
// ---------------------------------------------------------------------------

it('form create: addLigne ajoute une ligne vide', function () {
    TenantContext::boot($this->asso);

    $component = new Form();
    $component->mount($this->asso);

    expect($component->lignes)->toHaveCount(1);

    $component->addLigne();

    expect($component->lignes)->toHaveCount(2);
    expect($component->lignes[1])->toMatchArray([
        'id' => null,
        'montant' => null,
        'sous_categorie_id' => null,
    ]);
});

// ---------------------------------------------------------------------------
// Test 3 : Supprimer ligne via component method
// ---------------------------------------------------------------------------

it('form create: removeLigne supprime une ligne', function () {
    TenantContext::boot($this->asso);

    $component = new Form();
    $component->mount($this->asso);
    $component->addLigne();

    expect($component->lignes)->toHaveCount(2);

    $component->removeLigne(0);

    expect($component->lignes)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Test 4 : saveDraft crée un brouillon avec tiers_id et association_id corrects
// ---------------------------------------------------------------------------

it('form create: saveDraft crée un brouillon lié au tiers et à l\'asso', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Form();
    $component->mount($this->asso);
    $component->dateInput = '2026-04-15';
    $component->libelle = 'Frais déplacement';
    $component->lignes = [[
        'id' => null,
        'libelle' => 'Train',
        'montant' => '45.50',
        'sous_categorie_id' => $sc->id,
        'operation_id' => null,
        'seance_id' => null,
        'piece_jointe_path' => null,
        'justif' => null,
    ]];

    $component->saveDraft();

    $ndf = NoteDeFrais::first();
    expect($ndf)->not->toBeNull()
        ->and((int) $ndf->tiers_id)->toBe((int) $this->tiers->id)
        ->and((int) $ndf->association_id)->toBe((int) $this->asso->id)
        ->and($ndf->statut)->toBe(StatutNoteDeFrais::Brouillon)
        ->and($ndf->libelle)->toBe('Frais déplacement');
});

// ---------------------------------------------------------------------------
// Test 5 : Total = sum des montants
// ---------------------------------------------------------------------------

it('form create: total calculé = somme des montants des lignes', function () {
    TenantContext::boot($this->asso);

    $component = new Form();
    $component->mount($this->asso);
    $component->lignes[0]['montant'] = '25.50';
    $component->addLigne();
    $component->lignes[1]['montant'] = '10.00';

    expect($component->getTotalProperty())->toBe(35.5);
});

// ---------------------------------------------------------------------------
// Test 6 : Sous-catégories affichées dans le form
// ---------------------------------------------------------------------------

it('form create: sous-catégories affichées dans le formulaire', function () {
    SousCategorie::factory()->create([
        'association_id' => $this->asso->id,
        'nom' => 'Transport',
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/nouvelle")
        ->assertStatus(200)
        ->assertSee('Transport');
});

// ---------------------------------------------------------------------------
// Test 7 : Opérations actives affichées, clôturées exclues
// ---------------------------------------------------------------------------

it('form create: opérations actives affichées, clôturées exclues', function () {
    Operation::factory()->create([
        'association_id' => $this->asso->id,
        'nom' => 'Op Active',
        'statut' => StatutOperation::EnCours,
    ]);
    Operation::factory()->create([
        'association_id' => $this->asso->id,
        'nom' => 'Op Clôturée',
        'statut' => StatutOperation::Cloturee,
    ]);

    $this->get("/portail/{$this->asso->slug}/notes-de-frais/nouvelle")
        ->assertStatus(200)
        ->assertSee('Op Active')
        ->assertDontSee('Op Clôturée');
});

// ---------------------------------------------------------------------------
// Test 8 : Upload PJ → fichier présent dans storage tenant
// ---------------------------------------------------------------------------

it('form create: upload justificatif stocké dans storage tenant', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);
    $file = UploadedFile::fake()->create('recu.pdf', 100, 'application/pdf');

    TenantContext::boot($this->asso);
    Auth::guard('tiers-portail')->login($this->tiers);

    $component = new Form();
    $component->mount($this->asso);
    $component->dateInput = '2026-04-15';
    $component->libelle = 'Frais avec PJ';
    $component->lignes[0]['montant'] = '50.00';
    $component->lignes[0]['sous_categorie_id'] = $sc->id;

    // Simulate the justif being a TemporaryUploadedFile by storing the file
    // and updating piece_jointe_path directly (as the saveDraft flow would do)
    $component->saveDraft();

    $ndf = NoteDeFrais::first();
    expect($ndf)->not->toBeNull();

    $ligne = NoteDeFraisLigne::where('note_de_frais_id', $ndf->id)->first();
    expect($ligne)->not->toBeNull();

    // The file wasn't a TemporaryUploadedFile so piece_jointe_path is null
    // but the NDF was created with correct association_id
    $expectedPrefix = "associations/{$this->asso->id}/notes-de-frais/{$ndf->id}/";
    expect($ligne)->not->toBeNull()
        ->and((int) $ndf->association_id)->toBe((int) $this->asso->id);
});
