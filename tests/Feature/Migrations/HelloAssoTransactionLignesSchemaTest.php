<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('transaction_lignes table has helloasso_item_id column', function () {
    expect(Schema::hasColumn('transaction_lignes', 'helloasso_item_id'))->toBeTrue();
});

it('transaction_lignes table has exercice column', function () {
    expect(Schema::hasColumn('transaction_lignes', 'exercice'))->toBeTrue();
});

it('both helloasso columns are nullable', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $sousCat = SousCategorie::factory()->create();

    $transaction = Transaction::create([
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Test HA',
        'montant_total' => 50.00,
        'mode_paiement' => 'cb',
        'reference' => 'TEST-NULL',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
    ]);

    $ligne = TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
    ]);

    expect($ligne->helloasso_item_id)->toBeNull();
    expect($ligne->exercice)->toBeNull();
});

it('can store helloasso_item_id and exercice on a transaction ligne', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $sousCat = SousCategorie::factory()->create();

    $transaction = Transaction::create([
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Test HA',
        'montant_total' => 50.00,
        'mode_paiement' => 'cb',
        'reference' => 'TEST-STORE',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
    ]);

    $ligne = TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
        'helloasso_item_id' => 123456789,
        'exercice' => 2025,
    ]);

    $ligne->refresh();

    expect($ligne->helloasso_item_id)->toBe(123456789);
    expect($ligne->exercice)->toBe(2025);
});

it('helloasso_item_id is unique and rejects duplicates', function () {
    $user = User::factory()->create();
    $compte = CompteBancaire::factory()->create();
    $sousCat = SousCategorie::factory()->create();

    $transaction = Transaction::create([
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Test HA',
        'montant_total' => 100.00,
        'mode_paiement' => 'cb',
        'reference' => 'TEST-UNIQUE',
        'compte_id' => $compte->id,
        'saisi_par' => $user->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
        'helloasso_item_id' => 999888777,
        'exercice' => 2025,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
        'helloasso_item_id' => 999888777,
        'exercice' => 2025,
    ]);
})->throws(\Illuminate\Database\QueryException::class);
