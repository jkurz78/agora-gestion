<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Livewire\Exercices\ClotureWizard;
use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ClotureCheckService;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

it('signale et bloque N plus 1 entre la reouverture et la recloture de N', function (): void {
    config(['compta.use_partie_double' => true]);
    SystemeSeeder::seed();

    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->actingAs($user);

    $source = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);
    $banque = Compte::create([
        'numero_pcg' => '512-IND',
        'intitule' => 'Banque indisponibilité',
        'classe' => 5,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $produit = Compte::create([
        'numero_pcg' => '706-IND',
        'intitule' => 'Produit indisponibilité',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
    ]);
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => '2026-08-31',
        'libelle' => 'Source indisponibilité AN',
        'montant_total' => '40.00',
        'mode_paiement' => null,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Od,
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $banque->id,
        'debit' => '40.00',
        'credit' => '0.00',
        'montant' => '40.00',
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $produit->id,
        'debit' => '0.00',
        'credit' => '40.00',
        'montant' => '40.00',
    ]);

    $service = app(ExerciceService::class);
    $service->cloturer($source, $user);
    $service->reouvrir($source->fresh(), $user, 'Correction exceptionnelle');
    session(['exercice_actif' => 2026]);

    Livewire::test(ClotureWizard::class)
        ->assertSee('Soldes d’ouverture indisponibles')
        ->call('suite')
        ->assertSet('step', 1)
        ->assertSet('peutCloturer', false);

    $this->get(route('exercices.cloture'))
        ->assertOk()
        ->assertSee('Soldes d’ouverture indisponibles');
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Soldes d’ouverture indisponibles');

    $service->cloturer($source->fresh(), $user);

    Livewire::test(ClotureWizard::class)
        ->assertDontSee('Soldes d’ouverture indisponibles');

    $this->get(route('exercices.cloture'))
        ->assertOk()
        ->assertDontSee('Soldes d’ouverture indisponibles');
});

it('accepte une reprise initiale active meme si l exercice historique reste ouvert', function (): void {
    config(['compta.use_partie_double' => true]);

    $user = User::factory()->create();
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    $transaction = Transaction::create([
        'type' => TypeTransaction::AN,
        'date' => '2025-09-01',
        'libelle' => 'Reprise initiale AN',
        'montant_total' => '0.00',
        'mode_paiement' => null,
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::AN,
    ]);
    ANouveauGeneration::create([
        'exercice_source' => 2024,
        'exercice_cible' => 2025,
        'transaction_id' => $transaction->id,
        'origine' => OrigineANouveau::RepriseInitiale,
        'statut' => StatutANouveau::Active,
        'cree_par_id' => $user->id,
    ]);

    $controle = app(ClotureCheckService::class)->checkOuverturePrecedente(2025);

    expect($controle->ok)->toBeTrue()
        ->and($controle->message)->toBe('Les soldes d’ouverture sont disponibles');
});
