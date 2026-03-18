<?php

use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\BudgetService;

beforeEach(function () {
    $this->service = new BudgetService;
    $this->user = User::factory()->create();
});

it('computes realise for depense sous-categories', function () {
    $categorie = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorie->id]);

    // Depense in exercice 2025 (Sept 2025 - Aug 2026)
    $depense = Transaction::factory()->asDepense()->create([
        'date' => '2025-11-15',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 150.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 50.00,
    ]);

    // Depense outside exercice 2025
    $depenseOut = Transaction::factory()->asDepense()->create([
        'date' => '2024-10-15',
        'saisi_par' => $this->user->id,
    ]);
    $depenseOut->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $depenseOut->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 300.00,
    ]);

    $result = $this->service->realise($sc->id, 2025);

    expect($result)->toBe(200.0);
});

it('computes realise for recette sous-categories', function () {
    $categorie = Categorie::factory()->recette()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorie->id]);

    // Recette in exercice 2025
    $recette = Transaction::factory()->asRecette()->create([
        'date' => '2025-12-01',
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 500.00,
    ]);

    $result = $this->service->realise($sc->id, 2025);

    expect($result)->toBe(500.0);
});

it('returns 0 when no transactions', function () {
    $categorie = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorie->id]);

    $result = $this->service->realise($sc->id, 2025);

    expect($result)->toBe(0.0);
});
