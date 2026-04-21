<?php

declare(strict_types=1);

use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
});

// ---------------------------------------------------------------------------
// Test 1 : submit avec abandonCreanceProposed = true → colonne à true en DB
// ---------------------------------------------------------------------------

it('submit: abandonCreanceProposed=true persiste abandon_creance_propose=true', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);
    $assoId = (int) $this->asso->id;

    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais avec abandon créance',
        'date' => '2026-04-10',
        'abandon_creance_propose' => false,
    ]);
    $ligne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 50.00,
        'piece_jointe_path' => "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);

    Storage::disk('local')->put(
        "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
        'fake pdf content'
    );

    $component = new Form;
    $component->mount($this->asso, $ndf);
    $component->abandonCreanceProposed = true;
    $component->submit();

    $ndf->refresh();
    expect($ndf->abandon_creance_propose)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 2 : submit avec abandonCreanceProposed = false → colonne à false en DB
// ---------------------------------------------------------------------------

it('submit: abandonCreanceProposed=false persiste abandon_creance_propose=false', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);
    $assoId = (int) $this->asso->id;

    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Frais sans abandon créance',
        'date' => '2026-04-10',
        'abandon_creance_propose' => true, // commençons avec true pour bien vérifier le false
    ]);
    $ligne = NoteDeFraisLigne::factory()->create([
        'note_de_frais_id' => $ndf->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 50.00,
        'piece_jointe_path' => "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
    ]);

    Storage::disk('local')->put(
        "associations/{$assoId}/notes-de-frais/{$ndf->id}/ligne-1.pdf",
        'fake pdf content'
    );

    $component = new Form;
    $component->mount($this->asso, $ndf);
    $component->abandonCreanceProposed = false;
    $component->submit();

    $ndf->refresh();
    expect($ndf->abandon_creance_propose)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Test 3 : saveDraft avec abandonCreanceProposed = true → brouillon colonne à true
// ---------------------------------------------------------------------------

it('saveDraft: abandonCreanceProposed=true persiste abandon_creance_propose=true', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);

    $component = new Form;
    $component->mount($this->asso);
    $component->libelle = 'Brouillon abandon créance';
    $component->dateInput = '2026-04-10';
    $component->abandonCreanceProposed = true;
    $component->lignes = [[
        'id' => null,
        'type' => 'standard',
        'sous_categorie_id' => $sc->id,
        'operation_id' => null,
        'seance' => null,
        'libelle' => 'Repas',
        'montant' => '42.00',
        'piece_jointe_path' => null,
        'justif' => null,
        'cv_fiscaux' => null,
        'distance_km' => null,
        'bareme_eur_km' => null,
    ]];

    $component->saveDraft();

    $ndf = NoteDeFrais::first();
    expect($ndf)->not->toBeNull()
        ->and($ndf->abandon_creance_propose)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 4 : mount sur NDF existante avec abandon_creance_propose=true
//          → propriété abandonCreanceProposed hydratée à true
// ---------------------------------------------------------------------------

it('mount: hydrate abandonCreanceProposed depuis NDF existante', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF avec abandon créance',
        'abandon_creance_propose' => true,
    ]);

    $component = new Form;
    $component->mount($this->asso, $ndf);

    expect($component->abandonCreanceProposed)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 4b : mount sur NDF existante avec abandon_creance_propose=false
//           → propriété abandonCreanceProposed hydratée à false
// ---------------------------------------------------------------------------

it('mount: hydrate abandonCreanceProposed=false depuis NDF existante avec false', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'NDF sans abandon créance',
        'abandon_creance_propose' => false,
    ]);

    $component = new Form;
    $component->mount($this->asso, $ndf);

    expect($component->abandonCreanceProposed)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Test 5 : valeur par défaut = false pour nouvelle NDF
// ---------------------------------------------------------------------------

it('mount: abandonCreanceProposed vaut false par défaut pour une nouvelle NDF', function () {
    $component = new Form;
    $component->mount($this->asso);

    expect($component->abandonCreanceProposed)->toBeFalse();
});
