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

beforeEach(function (): void {
    $association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($association);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('transaction_lignes a la colonne helloasso_option_id', function (): void {
    expect(Schema::hasColumn('transaction_lignes', 'helloasso_option_id'))->toBeTrue();
});

it('accepte deux lignes avec le même helloasso_item_id et deux helloasso_option_id différents', function (): void {
    $compte = CompteBancaire::factory()->create();
    $sousCat = SousCategorie::factory()->create();

    $tx = Transaction::create([
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'HA test options',
        'montant_total' => 24.00,
        'mode_paiement' => 'cb',
        'reference' => 'HA-OPT-TEST',
        'compte_id' => $compte->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 12.00,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => 18596,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 12.00,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => 18597,
    ]);

    expect(TransactionLigne::where('helloasso_item_id', 87070)->count())->toBe(2);
});

it('rejette deux lignes avec le même helloasso_item_id et le même helloasso_option_id non-null', function (): void {
    $compte = CompteBancaire::factory()->create();
    $sousCat = SousCategorie::factory()->create();

    $tx = Transaction::create([
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'HA test dupli',
        'montant_total' => 24.00,
        'mode_paiement' => 'cb',
        'reference' => 'HA-OPT-DUP',
        'compte_id' => $compte->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 12.00,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => 18596,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 12.00,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => 18596,
    ]);
})->throws(QueryException::class);
