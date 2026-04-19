<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RapprochementBancaireService;
use App\Services\TransactionService;
use App\Services\VirementInterneService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
    $this->compte = CompteBancaire::factory()->create();
    $this->categorie = Categorie::factory()->create();
    $this->sousCategorie = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    // Exercice 2025 clôturé
    Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Cloture]);
    // Exercice 2024 ouvert
    Exercice::create(['annee' => 2024, 'statut' => StatutExercice::Ouvert]);
});

afterEach(function () {
    TenantContext::clear();
});

describe('TransactionService verrou', function () {
    it('blocks create on closed exercice', function () {
        app(TransactionService::class)->create(
            ['date' => '2025-10-15', 'type' => 'depense', 'compte_id' => $this->compte->id, 'montant_total' => 100, 'mode_paiement' => 'virement', 'reference' => 'TEST'],
            [['montant' => 100, 'sous_categorie_id' => $this->sousCategorie->id, 'operation_id' => null, 'seance' => null, 'notes' => null]]
        );
    })->throws(ExerciceCloturedException::class);

    it('allows create on open exercice', function () {
        $transaction = app(TransactionService::class)->create(
            ['date' => '2024-10-15', 'type' => 'depense', 'compte_id' => $this->compte->id, 'montant_total' => 100, 'mode_paiement' => 'virement', 'reference' => 'TEST'],
            [['montant' => 100, 'sous_categorie_id' => $this->sousCategorie->id, 'operation_id' => null, 'seance' => null, 'notes' => null]]
        );
        expect($transaction->id)->not->toBeNull();
    });

    it('blocks delete on closed exercice', function () {
        $transaction = Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $this->compte->id,
        ]);
        app(TransactionService::class)->delete($transaction);
    })->throws(ExerciceCloturedException::class);
});

describe('VirementInterneService verrou', function () {
    it('blocks create on closed exercice', function () {
        $compte2 = CompteBancaire::factory()->create();
        app(VirementInterneService::class)->create([
            'date' => '2025-10-15',
            'compte_source_id' => $this->compte->id,
            'compte_destination_id' => $compte2->id,
            'montant' => 100,
            'libelle' => 'Test',
        ]);
    })->throws(ExerciceCloturedException::class);
});

describe('RapprochementBancaireService verrou', function () {
    it('blocks create on closed exercice date_fin', function () {
        app(RapprochementBancaireService::class)->create(
            $this->compte,
            '2025-11-30',
            500.00
        );
    })->throws(ExerciceCloturedException::class);

    it('allows toggleTransaction when rapprochement is on open exercice even if transaction is from closed exercice', function () {
        $rapprochement = RapprochementBancaire::create([
            'compte_id' => $this->compte->id,
            'date_fin' => '2024-12-31',
            'solde_ouverture' => 0,
            'solde_fin' => 100,
            'statut' => StatutRapprochement::EnCours,
            'saisi_par' => $this->user->id,
        ]);

        $transaction = Transaction::factory()->asDepense()->create([
            'date' => '2025-10-15',
            'compte_id' => $this->compte->id,
            'montant_total' => 50,
        ]);

        // Should NOT throw — rapprochement is on open exercice 2024
        app(RapprochementBancaireService::class)->toggleTransaction($rapprochement, 'depense', $transaction->id);

        $transaction->refresh();
        expect($transaction->rapprochement_id)->toBe($rapprochement->id);
    });
});
