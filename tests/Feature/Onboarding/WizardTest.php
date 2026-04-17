<?php

declare(strict_types=1);

use App\Livewire\Onboarding\Wizard;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->unonboarded()->create([
        'wizard_current_step' => 1,
        'wizard_state' => null,
    ]);
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

it('loads at step 1 on fresh mount', function () {
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->assertSet('currentStep', 1);
});

it('resumes at persisted step on mount', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->assertSet('currentStep', 3);
});

it('allows jumping backwards to a previous step', function () {
    $this->association->update(['wizard_current_step' => 4]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 2)
        ->assertSet('currentStep', 2);
});

it('rejects jumping forward beyond current step', function () {
    $this->association->update(['wizard_current_step' => 2]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 5)
        ->assertSet('currentStep', 2);
});

it('hydrates state from wizard_state on mount', function () {
    $this->association->update(['wizard_state' => ['identite' => ['nom' => 'Foo']]]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->assertSet('state.identite.nom', 'Foo');
});

it('rejects goToStep 0 (out of lower bound)', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 0)
        ->assertSet('currentStep', 3);
});

it('rejects goToStep -1 (negative)', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', -1)
        ->assertSet('currentStep', 3);
});

it('rejects goToStep beyond TOTAL_STEPS', function () {
    $this->association->update(['wizard_current_step' => 5]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->call('goToStep', 10)
        ->assertSet('currentStep', 5);
});

it('saves step 1 identité and advances to step 2', function () {
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '1 rue de la Paix')
        ->set('identiteCodePostal', '75001')
        ->set('identiteVille', 'Paris')
        ->set('identiteEmail', 'contact@asso.example')
        ->set('identiteTelephone', '0123456789')
        ->set('identiteSiret', '12345678901234')
        ->set('identiteFormeJuridique', 'Association loi 1901')
        ->call('saveStep1')
        ->assertSet('currentStep', 2);

    $fresh = $this->association->fresh();
    expect($fresh->adresse)->toBe('1 rue de la Paix');
    expect($fresh->code_postal)->toBe('75001');
    expect($fresh->ville)->toBe('Paris');
    expect($fresh->email)->toBe('contact@asso.example');
    expect($fresh->telephone)->toBe('0123456789');
    expect($fresh->siret)->toBe('12345678901234');
    expect($fresh->forme_juridique)->toBe('Association loi 1901');
    expect($fresh->wizard_current_step)->toBe(2);
});

it('rejects step 1 with missing required fields', function () {
    // NOTE : mount() hydrates from factory defaults. Empty them explicitly to trigger required-rule errors.
    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '')
        ->set('identiteCodePostal', '')
        ->set('identiteVille', '')
        ->set('identiteEmail', '')
        ->call('saveStep1')
        ->assertHasErrors(['identiteAdresse', 'identiteCodePostal', 'identiteVille', 'identiteEmail']);
});

it('saves step 2 exercice (mois de début) and advances to step 3', function () {
    $this->association->update(['wizard_current_step' => 2]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('exerciceMoisDebut', 9)
        ->call('saveStep2')
        ->assertSet('currentStep', 3);

    expect($this->association->fresh()->exercice_mois_debut)->toBe(9);
    expect($this->association->fresh()->wizard_current_step)->toBe(3);
});

it('rejects step 2 with mois hors plage 1..12', function () {
    $this->association->update(['wizard_current_step' => 2]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('exerciceMoisDebut', 13)
        ->call('saveStep2')
        ->assertHasErrors(['exerciceMoisDebut']);
});

it('stores uploaded logo at associations/{id}/branding/ with short filename', function () {
    Storage::fake('local');
    $id = $this->association->id;
    $file = UploadedFile::fake()->image('original.png', 200, 200);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '1 rue X')
        ->set('identiteCodePostal', '75001')
        ->set('identiteVille', 'Paris')
        ->set('identiteEmail', 'a@b.c')
        ->set('logoUpload', $file)
        ->call('saveStep1')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists("associations/{$id}/branding/logo.png");
    expect($this->association->fresh()->logo_path)->toBe('logo.png');
});

it('stores uploaded cachet at associations/{id}/branding/ with short filename', function () {
    Storage::fake('local');
    $id = $this->association->id;
    $file = UploadedFile::fake()->image('signature.png', 200, 200);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('identiteAdresse', '1 rue X')
        ->set('identiteCodePostal', '75001')
        ->set('identiteVille', 'Paris')
        ->set('identiteEmail', 'a@b.c')
        ->set('cachetUpload', $file)
        ->call('saveStep1')
        ->assertHasNoErrors();

    Storage::disk('local')->assertExists("associations/{$id}/branding/cachet.png");
    expect($this->association->fresh()->cachet_signature_path)->toBe('cachet.png');
});

it('saves step 3 compte bancaire and advances to step 4', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('banqueNom', 'Compte courant principal')
        ->set('banqueIban', 'FR7630001007941234567890185')
        ->set('banqueBic', 'BDFEFRPPCCT')
        ->set('banqueDomiciliation', 'Banque de France Paris')
        ->set('banqueSoldeInitial', 1500.50)
        ->set('banqueDateSoldeInitial', '2026-01-01')
        ->call('saveStep3')
        ->assertSet('currentStep', 4);

    $compte = \App\Models\CompteBancaire::where('association_id', $this->association->id)->first();
    expect($compte)->not->toBeNull();
    expect($compte->nom)->toBe('Compte courant principal');
    expect($compte->iban)->toBe('FR7630001007941234567890185');
    expect($compte->bic)->toBe('BDFEFRPPCCT');
    expect((float) $compte->solde_initial)->toBe(1500.50);
    expect($compte->actif_recettes_depenses)->toBeTrue();
    expect($compte->est_systeme)->toBeFalse();
    expect($this->association->fresh()->wizard_current_step)->toBe(4);
});

it('rejects step 3 with missing IBAN', function () {
    $this->association->update(['wizard_current_step' => 3]);

    Livewire::actingAs($this->admin)
        ->test(Wizard::class)
        ->set('banqueNom', 'Test')
        ->set('banqueIban', '')
        ->call('saveStep3')
        ->assertHasErrors(['banqueIban']);
});
