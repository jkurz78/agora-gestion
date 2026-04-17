<?php

declare(strict_types=1);

use App\Livewire\Onboarding\Wizard;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
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
