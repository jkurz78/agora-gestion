<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Livewire\Exercices\ClotureWizard;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

it('renders step 1 with checks', function () {
    Livewire::test(ClotureWizard::class)
        ->assertOk()
        ->assertSee('pré-clôture')
        ->assertSet('step', 1);
});

it('can advance to step 2 when all blocking checks pass', function () {
    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->assertSet('step', 2);
});

it('cannot advance to step 2 when blocking checks fail', function () {
    $compte = CompteBancaire::factory()->create();
    RapprochementBancaire::create([
        'compte_id' => $compte->id,
        'date_fin' => '2025-11-30',
        'solde_ouverture' => 0,
        'solde_fin' => 100,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->assertSet('step', 1);
});

it('can navigate back from step 2', function () {
    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->call('goToStep', 1)
        ->assertSet('step', 1);
});

it('can advance to step 3', function () {
    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->call('suite')
        ->assertSet('step', 3);
});

it('can close the exercice from step 3', function () {
    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->call('suite')
        ->call('cloturer')
        ->assertRedirect(route('compta.exercices.changer'));

    $this->exercice->refresh();
    expect($this->exercice->statut)->toBe(StatutExercice::Cloture);
});

it('redirects if exercice is already closed', function () {
    $this->exercice->update(['statut' => StatutExercice::Cloture]);

    Livewire::test(ClotureWizard::class)
        ->assertRedirect(route('compta.exercices.changer'));
});
