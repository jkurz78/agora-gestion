<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 7 : Portail/NoteDeFrais/Form refuse un montant négatif.
 *
 * Form::wizardNext() à l'étape 2 validait déjà 'gt:0' mais avec un message
 * non standardisé : 'Le montant doit être supérieur à zéro.'. Le patch Step 7
 * remplace ce message par MontantValidation::MESSAGE pour cohérence applicative.
 *
 * Contexte d'authentification : guard 'tiers-portail' via Auth::guard('tiers-portail')->login($tiers).
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */

use App\Livewire\Concerns\MontantValidation;
use App\Livewire\Portail\NoteDeFrais\Form;
use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id]);
    Auth::guard('tiers-portail')->login($this->tiers);
    Storage::fake('local');
    Storage::fake('tmp-for-tests');
});

afterEach(function (): void {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helper — instancier le Form monté
// ---------------------------------------------------------------------------

function makeNdfForm(Association $asso): Form
{
    TenantContext::boot($asso);
    $component = new Form;
    $component->mount($asso);

    return $component;
}

// ---------------------------------------------------------------------------
// Test 1 : montant négatif à l'étape 2 → ValidationException + message standardisé
// ---------------------------------------------------------------------------

it('portail_ndf_form_refuse_montant_negatif_avec_message_standard', function (): void {
    $component = makeNdfForm($this->asso);
    $component->wizardStep = 2;
    $component->wizardType = 'standard';
    $component->draftLigne['montant'] = '-50';

    $exception = null;
    try {
        $component->wizardNext();
    } catch (ValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull('wizardNext() doit lever une ValidationException pour un montant négatif');

    $errors = $exception->errors();
    expect($errors)->toHaveKey('draftLigne.montant');

    $actualMessage = $errors['draftLigne.montant'][0];
    expect($actualMessage)->toBe(MontantValidation::MESSAGE);
});

// ---------------------------------------------------------------------------
// Test 2 : montant zéro à l'étape 2 → ValidationException + message standardisé
// ---------------------------------------------------------------------------

it('portail_ndf_form_refuse_montant_zero_avec_message_standard', function (): void {
    $component = makeNdfForm($this->asso);
    $component->wizardStep = 2;
    $component->wizardType = 'standard';
    $component->draftLigne['montant'] = '0';

    $exception = null;
    try {
        $component->wizardNext();
    } catch (ValidationException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull('wizardNext() doit lever une ValidationException pour un montant nul');

    $errors = $exception->errors();
    expect($errors)->toHaveKey('draftLigne.montant');

    $actualMessage = $errors['draftLigne.montant'][0];
    expect($actualMessage)->toBe(MontantValidation::MESSAGE);
});

// ---------------------------------------------------------------------------
// Test 3 : montant positif à l'étape 2 → pas d'exception, wizardStep = 3
// ---------------------------------------------------------------------------

it('portail_ndf_form_accepte_montant_positif_wizard_step2', function (): void {
    $component = makeNdfForm($this->asso);
    $component->wizardStep = 2;
    $component->wizardType = 'standard';
    $component->draftLigne['libelle'] = 'Repas client';
    $component->draftLigne['montant'] = '45.50';

    $component->wizardNext();

    expect($component->wizardStep)->toBe(3);
});
