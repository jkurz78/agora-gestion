<?php

declare(strict_types=1);

use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Association;
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
    // Fake le disk Livewire pour les uploads temporaires en tests
    Storage::fake('tmp-for-tests');
});

// ---------------------------------------------------------------------------
// Helper : construit un Form monté
// ---------------------------------------------------------------------------

function makeForm(Association $asso): Form
{
    TenantContext::boot($asso);
    $component = new Form;
    $component->mount($asso);

    return $component;
}

// ---------------------------------------------------------------------------
// Helper : crée un TemporaryUploadedFile valide pour les tests
// ---------------------------------------------------------------------------

function makeTmpFile(string $originalName = 'recu.pdf'): TemporaryUploadedFile
{
    // TemporaryUploadedFile::__construct() appelle FileUploadConfiguration::path($path, false)
    // qui préfixe avec 'livewire-tmp/', donc on passe uniquement le nom du fichier.
    $livewireDisk = Storage::disk('tmp-for-tests');
    $fullPath = 'livewire-tmp/'.$originalName;
    $livewireDisk->put($fullPath, 'fake content');

    return new TemporaryUploadedFile($originalName, 'tmp-for-tests');
}

// ---------------------------------------------------------------------------
// Test 1 : openLigneWizard → wizardStep === 1
// ---------------------------------------------------------------------------

it('wizard: openLigneWizard passe wizardStep à 1', function () {
    $component = makeForm($this->asso);

    expect($component->wizardStep)->toBe(0);

    $component->openLigneWizard();

    expect($component->wizardStep)->toBe(1);
});

// ---------------------------------------------------------------------------
// Test 2 : wizardNext sans justif → erreur validation étape 1
// ---------------------------------------------------------------------------

it('wizard: wizardNext sans justif produit une erreur de validation', function () {
    $component = makeForm($this->asso);
    $component->openLigneWizard();

    // Pas de justif fourni → draftLigne.justif est null
    expect(fn () => $component->wizardNext())
        ->toThrow(ValidationException::class);

    // Toujours à l'étape 1
    expect($component->wizardStep)->toBe(1);
});

// ---------------------------------------------------------------------------
// Test 3 : wizardNext avec justif valide → wizardStep === 2
// ---------------------------------------------------------------------------

it('wizard: wizardNext avec justif valide passe à l\'étape 2', function () {
    $component = makeForm($this->asso);
    $component->openLigneWizard();

    $component->draftLigne['justif'] = makeTmpFile('recu.pdf');
    $component->wizardNext();

    expect($component->wizardStep)->toBe(2);
});

// ---------------------------------------------------------------------------
// Test 4 : Étape 2 — libellé vide OK, montant vide → erreur
// ---------------------------------------------------------------------------

it('wizard: à l\'étape 2, montant vide produit une erreur', function () {
    $component = makeForm($this->asso);
    $component->wizardStep = 2;
    $component->draftLigne['libelle'] = '';
    $component->draftLigne['montant'] = '';

    expect(fn () => $component->wizardNext())
        ->toThrow(ValidationException::class);

    expect($component->wizardStep)->toBe(2);
});

// ---------------------------------------------------------------------------
// Test 5 : Étape 2 — montant valide → wizardStep === 3
// ---------------------------------------------------------------------------

it('wizard: à l\'étape 2, montant valide passe à l\'étape 3', function () {
    $component = makeForm($this->asso);
    $component->wizardStep = 2;
    $component->draftLigne['libelle'] = 'Repas';
    $component->draftLigne['montant'] = '25.50';

    $component->wizardNext();

    expect($component->wizardStep)->toBe(3);
});

// ---------------------------------------------------------------------------
// Test 6 : Étape 3 — sous-cat manquante → erreur
// ---------------------------------------------------------------------------

it('wizard: wizardConfirm sans sous-catégorie produit une erreur', function () {
    $component = makeForm($this->asso);
    $component->wizardStep = 3;
    $component->draftLigne['sous_categorie_id'] = null;

    expect(fn () => $component->wizardConfirm())
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Test 7 : wizardConfirm complet → ligne ajoutée, draftLigne réinitialisé, wizardStep = 0
// ---------------------------------------------------------------------------

it('wizard: wizardConfirm ajoute la ligne à $lignes, reset draftLigne, wizardStep = 0', function () {
    $sc = SousCategorie::factory()->create(['association_id' => $this->asso->id]);

    $component = makeForm($this->asso);
    $component->wizardStep = 3;

    $tmpFile = makeTmpFile('recu.pdf');

    $component->draftLigne = [
        'justif' => $tmpFile,
        'libelle' => 'Déplacement Lyon',
        'montant' => '45.00',
        'sous_categorie_id' => (string) $sc->id,
        'operation_id' => null,
        'seance_id' => null,
    ];

    expect($component->lignes)->toHaveCount(0);

    $component->wizardConfirm();

    expect($component->lignes)->toHaveCount(1)
        ->and($component->lignes[0]['libelle'])->toBe('Déplacement Lyon')
        ->and($component->lignes[0]['montant'])->toBe('45.00')
        ->and($component->lignes[0]['sous_categorie_id'])->toBe((string) $sc->id)
        ->and($component->wizardStep)->toBe(0)
        ->and($component->draftLigne['libelle'])->toBe('')
        ->and($component->draftLigne['montant'])->toBe('')
        ->and($component->draftLigne['justif'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 8 : cancelLigneWizard à tout moment → reset
// ---------------------------------------------------------------------------

it('wizard: cancelLigneWizard réinitialise wizardStep et draftLigne', function () {
    $component = makeForm($this->asso);
    $component->wizardStep = 2;
    $component->draftLigne['libelle'] = 'En cours';
    $component->draftLigne['montant'] = '99.00';

    $component->cancelLigneWizard();

    expect($component->wizardStep)->toBe(0)
        ->and($component->draftLigne['libelle'])->toBe('')
        ->and($component->draftLigne['montant'])->toBe('');
});

// ---------------------------------------------------------------------------
// Test 9 : wizardPrev → retour étape précédente sans perdre les données
// ---------------------------------------------------------------------------

it('wizard: wizardPrev retourne à l\'étape précédente sans perdre les données', function () {
    $component = makeForm($this->asso);
    $component->wizardStep = 3;
    $component->draftLigne['libelle'] = 'Transport';
    $component->draftLigne['montant'] = '12.50';

    $component->wizardPrev();

    expect($component->wizardStep)->toBe(2)
        ->and($component->draftLigne['libelle'])->toBe('Transport')
        ->and($component->draftLigne['montant'])->toBe('12.50');

    $component->wizardPrev();

    expect($component->wizardStep)->toBe(1);
});
