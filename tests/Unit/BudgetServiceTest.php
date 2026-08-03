<?php

use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\BudgetService;

beforeEach(function () {
    $this->service = new BudgetService;
    $this->user = User::factory()->create();
});

/** Ligne de ventilation compte-first (dépense: débit, recette: crédit). */
function budgetServiceTestLigne(Transaction $tx, Compte $compte, float $montant): TransactionLigne
{
    $estDepense = $tx->type->value === 'depense';

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'montant' => $montant,
        'compte_id' => $compte->id,
        'debit' => $estDepense ? $montant : 0.0,
        'credit' => $estDepense ? 0.0 : $montant,
    ]);
}

it('computes realise for comptes de classe 6 (depense)', function () {
    $compte = Compte::factory()->numero('606')->create();

    // Depense in exercice 2025 (Sept 2025 - Aug 2026)
    $depense = Transaction::factory()->asDepense()->create([
        'date' => '2025-11-15',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    budgetServiceTestLigne($depense, $compte, 150.00);
    budgetServiceTestLigne($depense, $compte, 50.00);

    // Depense outside exercice 2025
    $depenseOut = Transaction::factory()->asDepense()->create([
        'date' => '2024-10-15',
        'saisi_par' => $this->user->id,
    ]);
    $depenseOut->lignes()->forceDelete();
    budgetServiceTestLigne($depenseOut, $compte, 300.00);

    $result = $this->service->realise((int) $compte->id, 2025);

    expect($result)->toBe(200.0);
});

it('computes realise for comptes de classe 7 (recette)', function () {
    $compte = Compte::factory()->numero('706')->create();

    // Recette in exercice 2025
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-12-01',
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    budgetServiceTestLigne($recette, $compte, 500.00);

    $result = $this->service->realise((int) $compte->id, 2025);

    expect($result)->toBe(500.0);
});

it('returns 0 when no transactions', function () {
    $compte = Compte::factory()->numero('616')->create();

    $result = $this->service->realise((int) $compte->id, 2025);

    expect($result)->toBe(0.0);
});
