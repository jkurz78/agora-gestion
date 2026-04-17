<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutExercice;
use App\Enums\StatutFacture;
use App\Enums\TypeTransaction;
use App\Exceptions\ExerciceCloturedException;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Facture;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Models\Association;
use App\Tenant\TenantContext;
use App\Services\FactureService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->compteBancaire = CompteBancaire::factory()->create();
    $this->sousCategorie = SousCategorie::factory()->create(['nom' => 'Inscription']);
    $this->service = app(FactureService::class);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Helper: create a brouillon facture with one montant ligne via transactions.
 */
function createBrouillonWithLignes(
    object $context,
    float $montant = 100.00,
    string $date = '2025-11-15',
    int $exercice = 2025,
): Facture {
    $facture = Facture::create([
        'date' => $date,
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $context->tiers->id,
        'saisi_par' => $context->user->id,
        'exercice' => $exercice,
        'montant_total' => 0,
    ]);

    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => $date,
        'libelle' => 'Test recette',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Virement,
        'compte_id' => $context->compteBancaire->id,
        'tiers_id' => $context->tiers->id,
        'saisi_par' => $context->user->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $context->sousCategorie->id,
        'montant' => $montant,
    ]);

    $context->service->ajouterTransactions($facture, [$transaction->id]);

    return $facture->fresh();
}

describe('valider()', function () {
    it('assigns sequential numero F-{exercice}-0001', function () {
        $facture = createBrouillonWithLignes($this);

        $this->service->valider($facture);

        $facture->refresh();
        expect($facture->numero)->toBe('F-2025-0001');
    });

    it('freezes montant_total as sum of montant lignes', function () {
        $facture = Facture::create([
            'date' => '2025-11-15',
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        $transaction1 = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-11-15',
            'libelle' => 'Recette 1',
            'montant_total' => 150.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $this->compteBancaire->id,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
        ]);

        TransactionLigne::create([
            'transaction_id' => $transaction1->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'montant' => 75.50,
        ]);

        TransactionLigne::create([
            'transaction_id' => $transaction1->id,
            'sous_categorie_id' => $this->sousCategorie->id,
            'montant' => 49.50,
        ]);

        $this->service->ajouterTransactions($facture, [$transaction1->id]);

        $this->service->valider($facture);

        $facture->refresh();
        expect((float) $facture->montant_total)->toBe(125.00);
    });

    it('changes statut to validee', function () {
        $facture = createBrouillonWithLignes($this);

        $this->service->valider($facture);

        $facture->refresh();
        expect($facture->statut)->toBe(StatutFacture::Validee);
    });

    it('rejects empty facture (no montant lines)', function () {
        $facture = Facture::create([
            'date' => '2025-11-15',
            'statut' => StatutFacture::Brouillon,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
            'exercice' => 2025,
            'montant_total' => 0,
        ]);

        $this->service->valider($facture);
    })->throws(RuntimeException::class, 'La facture doit contenir au moins une ligne avec montant.');

    it('rejects if exercice is closed', function () {
        Exercice::create([
            'annee' => 2025,
            'statut' => StatutExercice::Cloture,
            'date_cloture' => now(),
        ]);

        $facture = createBrouillonWithLignes($this);

        $this->service->valider($facture);
    })->throws(ExerciceCloturedException::class);

    it('rejects if date < last validated facture date (chronological constraint)', function () {
        // Create and validate a facture with later date
        $facture1 = createBrouillonWithLignes($this, 100.00, '2025-12-15');
        $this->service->valider($facture1);

        // Try to validate a facture with earlier date
        $facture2 = createBrouillonWithLignes($this, 50.00, '2025-11-10');

        $this->service->valider($facture2);
    })->throws(RuntimeException::class, 'La date doit être postérieure ou égale au 15/12/2025 (dernière facture validée F-2025-0001).');

    it('correctly increments sequence across 3 factures', function () {
        $facture1 = createBrouillonWithLignes($this, 100.00, '2025-11-01');
        $this->service->valider($facture1);

        $facture2 = createBrouillonWithLignes($this, 200.00, '2025-11-15');
        $this->service->valider($facture2);

        $facture3 = createBrouillonWithLignes($this, 300.00, '2025-12-01');
        $this->service->valider($facture3);

        expect($facture1->fresh()->numero)->toBe('F-2025-0001')
            ->and($facture2->fresh()->numero)->toBe('F-2025-0002')
            ->and($facture3->fresh()->numero)->toBe('F-2025-0003');
    });

    it('zero-pads the sequence to 4 digits', function () {
        $facture = createBrouillonWithLignes($this);

        $this->service->valider($facture);

        $facture->refresh();
        // The numero should end with 0001, not 1
        expect($facture->numero)->toMatch('/^F-2025-\d{4}$/')
            ->and($facture->numero)->toBe('F-2025-0001');
    });

    it('rejects validating a non-brouillon facture', function () {
        $facture = createBrouillonWithLignes($this);
        $this->service->valider($facture);

        // Now try to validate again (it's already validée)
        $facture->refresh();
        $this->service->valider($facture);
    })->throws(RuntimeException::class, 'Seul un brouillon peut être validé.');
});
