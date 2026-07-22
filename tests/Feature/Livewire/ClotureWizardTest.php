<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\Exercices\ClotureWizard;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->exercice = Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
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

it('affiche le compte des provisions dans le récapitulatif', function () {
    $compte = Compte::factory()->depense()->create(['intitule' => 'Compte clôture']);
    Provision::factory()->create([
        'exercice' => 2025,
        'compte_id' => $compte->id,
        'saisi_par' => $this->user->id,
        'date' => '2026-08-31',
        'libelle' => 'Provision clôture',
    ]);

    Livewire::test(ClotureWizard::class)
        ->call('suite')
        ->assertSet('step', 2)
        ->assertSee('Compte clôture');
});

it('fige la réconciliation bancaire à la date de clôture', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
    $rapprochementFutur = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'date_fin' => '2026-09-30',
        'solde_ouverture' => 1000.00,
        'solde_fin' => 5000.00,
        'statut' => StatutRapprochement::Verrouille,
        'saisi_par' => $this->user->id,
    ]);
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => '2026-08-31',
        'montant_total' => 100.00,
        'rapprochement_id' => $rapprochementFutur->id,
        'statut_reglement' => StatutReglement::Pointe,
    ]);
    Transaction::factory()->create([
        'type' => TypeTransaction::AN,
        'date' => '2025-09-01',
        'montant_total' => 1000.00,
        'compte_id' => null,
        'rapprochement_id' => null,
    ]);

    expect($recette->journal)->toBe(JournalComptable::Vente);

    $component = Livewire::test(ClotureWizard::class)
        ->set('step', 2)
        ->assertSet('step', 2);

    $summary = $component->viewData('summary');

    expect($summary['totalSoldeRapprochement'])->toBe(1000.0)
        ->and($summary['nombreNonPointees'])->toBe(1)
        ->and($summary['montantNetNonPointeesTx'])->toBe(100.0)
        ->and($summary['totalSoldeReel'])->toBe(1100.0)
        ->and($summary['ecartReconciliation'])->toBe(0.0);
});

it('cannot advance to step 2 when blocking checks fail', function () {
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    RapprochementBancaire::create([
        'association_id' => $this->association->id,
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
        ->assertRedirect(route('exercices.changer'));

    $this->exercice->refresh();
    expect($this->exercice->statut)->toBe(StatutExercice::Cloture);
});

it('redirects if exercice is already closed', function () {
    $this->exercice->update(['statut' => StatutExercice::Cloture]);

    Livewire::test(ClotureWizard::class)
        ->assertRedirect(route('exercices.changer'));
});
