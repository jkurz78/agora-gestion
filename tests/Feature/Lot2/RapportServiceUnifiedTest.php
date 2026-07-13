<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RapportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes don-type recettes in produits', function () {
    $compteDon = Compte::factory()->numero('754')->pourDons()->create(['intitule' => 'Dons manuels']);

    $compte = CompteBancaire::factory()->create();
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => '2025-11-15',
        'montant_total' => 150.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteDon->id,
        'montant' => 150.00,
        'debit' => 0.0,
        'credit' => 150.00,
    ]);

    $result = app(RapportService::class)->compteDeResultat(2025);

    $produits = collect($result['produits']);
    $found = $produits->flatMap(fn ($famille) => $famille['comptes'])
        ->firstWhere('compte_nom', 'Dons manuels');

    expect($found)->not->toBeNull();
    expect($found['montant_n'])->toBe(150.00);
});

it('includes cotisation-type recettes in produits', function () {
    $compteCot = Compte::factory()->numero('756')->pourCotisations()->create(['intitule' => 'Cotisations']);

    $compte = CompteBancaire::factory()->create();
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => '2025-10-01',
        'montant_total' => 80.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteCot->id,
        'montant' => 80.00,
        'debit' => 0.0,
        'credit' => 80.00,
    ]);

    $result = app(RapportService::class)->compteDeResultat(2025);

    $produits = collect($result['produits']);
    $found = $produits->flatMap(fn ($famille) => $famille['comptes'])
        ->firstWhere('compte_nom', 'Cotisations');

    expect($found)->not->toBeNull();
    expect($found['montant_n'])->toBe(80.00);
});
