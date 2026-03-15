<?php

use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
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
    $depense = Depense::factory()->create([
        'date' => '2025-11-15',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 150.00,
    ]);
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 50.00,
    ]);

    // Depense outside exercice 2025
    $depenseOut = Depense::factory()->create([
        'date' => '2024-10-15',
        'saisi_par' => $this->user->id,
    ]);
    $depenseOut->lignes()->forceDelete();
    DepenseLigne::factory()->create([
        'depense_id' => $depenseOut->id,
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
    $recette = Recette::factory()->create([
        'date' => '2025-12-01',
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
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
