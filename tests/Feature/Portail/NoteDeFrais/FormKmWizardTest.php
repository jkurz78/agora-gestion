<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
    Storage::fake('tmp-for-tests');
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function makeKmForm(Association $asso): Form
{
    TenantContext::boot($asso);
    $component = new Form;
    $component->mount($asso);

    return $component;
}

function makeTmpFileKm(string $originalName = 'carte-grise.pdf'): TemporaryUploadedFile
{
    $livewireDisk = Storage::disk('tmp-for-tests');
    $fullPath = 'livewire-tmp/'.$originalName;
    $livewireDisk->put($fullPath, 'fake content');

    return new TemporaryUploadedFile($originalName, 'tmp-for-tests');
}

// ---- Task 8: state + live compute -----------------------------------------

it('ouvre le wizard km et reset le draft', function () {
    $component = makeKmForm($this->asso);

    $component->openKilometriqueWizard();

    expect($component->wizardStep)->toBe(1);
    expect($component->wizardType)->toBe('kilometrique');
    expect($component->draftLigne['cv_fiscaux'])->toBeNull();
    expect($component->draftLigne['distance_km'])->toBeNull();
    expect($component->draftLigne['bareme_eur_km'])->toBeNull();
});

it('peut fermer le wizard km', function () {
    $component = makeKmForm($this->asso);

    $component->openKilometriqueWizard();
    $component->cancelLigneWizard();

    expect($component->wizardStep)->toBe(0);
    expect($component->wizardType)->toBeNull();
});

it('ouvrir wizard standard remet wizardType à standard', function () {
    $component = makeKmForm($this->asso);

    $component->openLigneWizard();

    expect($component->wizardStep)->toBe(1);
    expect($component->wizardType)->toBe('standard');
});

it('computed montant km = distance x bareme', function () {
    $component = makeKmForm($this->asso);

    $component->openKilometriqueWizard();
    $component->draftLigne['distance_km'] = '420';
    $component->draftLigne['bareme_eur_km'] = '0,636';

    expect($component->getDraftMontantCalculeProperty())->toBe(267.12);
});

it('computed montant km = 0 si champs manquants', function () {
    $component = makeKmForm($this->asso);

    $component->openKilometriqueWizard();

    expect($component->getDraftMontantCalculeProperty())->toBe(0.0);
});

// ---- Task 9: navigation + confirmation ------------------------------------

it('étape 1 wizard km exige la carte grise', function () {
    $component = makeKmForm($this->asso);
    $component->openKilometriqueWizard();

    expect(fn () => $component->wizardNext())
        ->toThrow(ValidationException::class);

    expect($component->wizardStep)->toBe(1);
});

it('étape 1 km → étape 2 avec la carte grise uploadée', function () {
    $component = makeKmForm($this->asso);
    $component->openKilometriqueWizard();
    $component->draftLigne['justif'] = makeTmpFileKm('carte-grise.pdf');

    $component->wizardNext();

    expect($component->wizardStep)->toBe(2);
});

it('étape 2 km valide CV + km + bareme + libellé', function () {
    $component = makeKmForm($this->asso);
    $component->wizardType = 'kilometrique';
    $component->wizardStep = 2;
    $component->draftLigne['justif'] = makeTmpFileKm('carte-grise.pdf');
    // All required km fields left empty → validation must fail

    expect(fn () => $component->wizardConfirm())
        ->toThrow(ValidationException::class);

    // wizardStep unchanged — still at step 2
    expect($component->wizardStep)->toBe(2);
});

it('confirme la ligne km et l\'ajoute au tableau des lignes', function () {
    $cat = Categorie::factory()->create(['association_id' => $this->asso->id]);
    SousCategorie::create([
        'association_id' => $this->asso->id,
        'categorie_id' => $cat->id,
        'nom' => 'Déplacements',
        'pour_frais_kilometriques' => true,
    ]);

    $component = makeKmForm($this->asso);
    $component->openKilometriqueWizard();
    $component->draftLigne['justif'] = makeTmpFileKm('carte-grise.pdf');
    $component->wizardNext(); // step 1 → 2

    $component->draftLigne['libelle'] = 'Paris-Rennes AG';
    $component->draftLigne['cv_fiscaux'] = 5;
    $component->draftLigne['distance_km'] = '420';
    $component->draftLigne['bareme_eur_km'] = '0,636';

    $component->wizardConfirm();

    expect($component->wizardStep)->toBe(0);
    expect($component->wizardType)->toBeNull();
    expect($component->lignes)->toHaveCount(1);
    expect($component->lignes[0]['type'])->toBe('kilometrique');
    expect($component->lignes[0]['libelle'])->toBe('Paris-Rennes AG');
    expect((float) $component->lignes[0]['montant'])->toBe(267.12);
});

it('affiche les deux boutons "Ajouter"', function () {
    $response = $this->get("/portail/{$this->asso->slug}/notes-de-frais/nouvelle");

    $response->assertStatus(200);
    $response->assertSee('Ajouter une ligne de dépense');
    $response->assertSee('Ajouter un déplacement');
});

it('recharge une ligne km existante en édition avec ses métadonnées', function () {
    $ndf = NoteDeFrais::create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'date' => '2026-04-20',
        'libelle' => 'NDF avril',
        'statut' => StatutNoteDeFrais::Brouillon->value,
    ]);

    NoteDeFraisLigne::create([
        'note_de_frais_id' => $ndf->id,
        'type' => NoteDeFraisLigneType::Kilometrique->value,
        'libelle' => 'Paris-Rennes AG',
        'montant' => 267.12,
        'metadata' => ['cv_fiscaux' => 5, 'distance_km' => 420, 'bareme_eur_km' => 0.636],
    ]);

    TenantContext::boot($this->asso);
    $component = new Form;
    $component->mount($this->asso, $ndf);

    expect($component->lignes)->toHaveCount(1);

    $ligne = $component->lignes[0];
    expect($ligne['type'])->toBe('kilometrique');
    expect($ligne['cv_fiscaux'])->toBe(5);
    expect((int) $ligne['distance_km'])->toBe(420);
    expect((float) $ligne['bareme_eur_km'])->toBe(0.636);
});
