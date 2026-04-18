<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

it('transaction_lignes table has helloasso_item_id column', function () {
    expect(Schema::hasColumn('transaction_lignes', 'helloasso_item_id'))->toBeTrue();
});

it('transaction_lignes table no longer has exercice column', function () {
    expect(Schema::hasColumn('transaction_lignes', 'exercice'))->toBeFalse();
});

it('helloasso_item_id is nullable', function () {
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
});

it('can store helloasso_item_id on a transaction ligne', function () {
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
    ]);

    $ligne->refresh();

    expect($ligne->helloasso_item_id)->toBe(123456789);
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
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
        'helloasso_item_id' => 999888777,
    ]);
})->throws(QueryException::class);
