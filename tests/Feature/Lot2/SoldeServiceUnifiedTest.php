<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Services\SoldeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes solde from transactions only without don/cotisation tables', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);

    Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'montant_total' => 200.00,
        'date' => '2025-10-01',
    ]);

    Transaction::factory()->asDepense()->create([
        'compte_id' => $compte->id,
        'montant_total' => 50.00,
        'date' => '2025-10-15',
    ]);

    $solde = app(SoldeService::class)->solde($compte);

    expect($solde)->toBe(1150.00);
});

it('excludes transactions before date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial' => 500.00,
        'date_solde_initial' => '2025-10-01',
    ]);

    Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'montant_total' => 100.00,
        'date' => '2025-09-15',
    ]);

    Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'montant_total' => 300.00,
        'date' => '2025-11-01',
    ]);

    $solde = app(SoldeService::class)->solde($compte);

    expect($solde)->toBe(800.00);
});
