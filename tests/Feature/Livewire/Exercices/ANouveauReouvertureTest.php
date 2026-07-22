<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\StatutANouveau;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Livewire\Exercices\ReouvrirExercice;
use App\Models\ANouveauGeneration;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

it('reouvre l exercice et invalide immediatement sa piece AN', function (): void {
    config(['compta.use_partie_double' => true]);
    SystemeSeeder::seed();

    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);
    session(['exercice_actif' => 2025]);

    $exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Ouvert]);
    $banque = Compte::create([
        'numero_pcg' => '512-RV', 'intitule' => 'Banque réouverture', 'classe' => 5,
        'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false, 'lettrable' => false,
    ]);
    $produit = Compte::create([
        'numero_pcg' => '706-RV', 'intitule' => 'Produit réouverture', 'classe' => 7,
        'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false, 'lettrable' => false,
    ]);
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette, 'date' => '2026-08-31', 'libelle' => 'Source réouverture',
        'montant_total' => '30.00', 'mode_paiement' => null, 'equilibree' => true,
        'type_ecriture' => 'normale', 'journal' => JournalComptable::Od,
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id, 'compte_id' => $banque->id,
        'debit' => '30.00', 'credit' => '0.00', 'montant' => '30.00',
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id, 'compte_id' => $produit->id,
        'debit' => '0.00', 'credit' => '30.00', 'montant' => '30.00',
    ]);

    app(ExerciceService::class)->cloturer($exercice, $user);
    $generation = ANouveauGeneration::activePourCible(2026);
    $transactionANId = (int) $generation?->transaction_id;

    Livewire::test(ReouvrirExercice::class)
        ->assertSee('La pièce d’à-nouveaux sera invalidée')
        ->set('commentaire', 'Correction exceptionnelle après clôture')
        ->call('reouvrir');

    expect($exercice->fresh()->statut)->toBe(StatutExercice::Ouvert)
        ->and($generation?->fresh()->statut)->toBe(StatutANouveau::Invalidee)
        ->and(Transaction::withTrashed()->findOrFail($transactionANId)->trashed())->toBeTrue();
});
