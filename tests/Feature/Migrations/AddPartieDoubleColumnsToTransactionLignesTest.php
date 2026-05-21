<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Step 6 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Asserts the structural shape of the columns added to `transaction_lignes`
 * by 2026_05_20_000004_add_partie_double_columns_to_transaction_lignes.php
 * (schema per spec §2.2).
 *
 * No backfill is performed here — that is Step 32.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

it('has all 6 new columns on transaction_lignes', function () {
    $newColumns = [
        'compte_id',
        'debit',
        'credit',
        'tiers_id',
        'lettrage_code',
        'libelle',
    ];

    foreach ($newColumns as $column) {
        expect(Schema::hasColumn('transaction_lignes', $column))->toBeTrue(
            "Column {$column} should exist on transaction_lignes"
        );
    }
});

it('debit and credit have correct type (decimal)', function () {
    $debitType = Schema::getColumnType('transaction_lignes', 'debit');
    $creditType = Schema::getColumnType('transaction_lignes', 'credit');

    // Doctrine DBAL / Laravel may report "decimal" on MySQL or "numeric" on SQLite.
    // Both are acceptable for a DECIMAL column.
    expect($debitType)->toBeIn(['decimal', 'float', 'double', 'numeric']);
    expect($creditType)->toBeIn(['decimal', 'float', 'double', 'numeric']);
});

it('compte_id and tiers_id have bigint type', function () {
    $compteIdType = Schema::getColumnType('transaction_lignes', 'compte_id');
    $tiersIdType = Schema::getColumnType('transaction_lignes', 'tiers_id');

    // Doctrine DBAL reports "bigint" for BIGINT UNSIGNED columns.
    expect($compteIdType)->toBeIn(['bigint', 'integer']);
    expect($tiersIdType)->toBeIn(['bigint', 'integer']);
});

it('lettrage_code and libelle have string type', function () {
    $lettrageType = Schema::getColumnType('transaction_lignes', 'lettrage_code');
    $libelleType = Schema::getColumnType('transaction_lignes', 'libelle');

    expect($lettrageType)->toBeIn(['string', 'varchar']);
    expect($libelleType)->toBeIn(['string', 'varchar']);
});

it('debit and credit default to 0 when not provided', function () {
    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $ligneId = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => SousCategorie::factory()->create()->id,
        'montant' => 42.50,
    ]);

    $ligne = DB::table('transaction_lignes')->find($ligneId);

    expect((float) $ligne->debit)->toBe(0.0);
    expect((float) $ligne->credit)->toBe(0.0);
});

it('compte_id and tiers_id accept NULL', function () {
    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $ligneId = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => SousCategorie::factory()->create()->id,
        'montant' => 10.00,
        'compte_id' => null,
        'tiers_id' => null,
    ]);

    $ligne = DB::table('transaction_lignes')->find($ligneId);

    expect($ligne->compte_id)->toBeNull();
    expect($ligne->tiers_id)->toBeNull();
});

it('lettrage_code and libelle accept NULL', function () {
    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $ligneId = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => SousCategorie::factory()->create()->id,
        'montant' => 10.00,
        'lettrage_code' => null,
        'libelle' => null,
    ]);

    $ligne = DB::table('transaction_lignes')->find($ligneId);

    expect($ligne->lettrage_code)->toBeNull();
    expect($ligne->libelle)->toBeNull();
});

it('has the expected indexes on transaction_lignes', function () {
    $indexes = Schema::getIndexes('transaction_lignes');

    // Build a map keyed by sorted column tuple → metadata for easy lookup.
    $byColumns = collect($indexes)->mapWithKeys(function ($index) {
        return [implode(',', $index['columns']) => $index];
    });

    expect($byColumns->has('compte_id,tiers_id,lettrage_code'))->toBeTrue(
        'Missing composite index on (compte_id, tiers_id, lettrage_code)'
    );
    expect($byColumns->has('lettrage_code'))->toBeTrue(
        'Missing single-column index on (lettrage_code)'
    );
    expect($byColumns->has('compte_id,tiers_id'))->toBeTrue(
        'Missing composite index on (compte_id, tiers_id)'
    );
});

it('sous_categorie_id is still present and nullable', function () {
    expect(Schema::hasColumn('transaction_lignes', 'sous_categorie_id'))->toBeTrue();

    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    // Should succeed with null sous_categorie_id
    $ligneId = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => null,
        'montant' => 5.00,
    ]);

    $ligne = DB::table('transaction_lignes')->find($ligneId);
    expect($ligne->sous_categorie_id)->toBeNull();
});

it('montant is still present and accepts decimal values', function () {
    expect(Schema::hasColumn('transaction_lignes', 'montant'))->toBeTrue();

    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $ligneId = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => SousCategorie::factory()->create()->id,
        'montant' => 123.45,
    ]);

    $ligne = DB::table('transaction_lignes')->find($ligneId);
    expect((float) $ligne->montant)->toBe(123.45);
});

it('existing rows survive the migration with defaults applied correctly', function () {
    // Create a ligne via the factory (old-schema style: only legacy columns set)
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => Transaction::factory()->create([
            'association_id' => $this->association->id,
        ])->id,
        'montant' => 99.99,
        'notes' => 'legacy row',
    ]);

    $row = DB::table('transaction_lignes')->find($ligne->id);

    // Legacy data intact
    expect($row)->not->toBeNull();
    expect((int) $row->transaction_id)->toBe($ligne->transaction_id);
    expect((float) $row->montant)->toBe(99.99);
    expect($row->notes)->toBe('legacy row');

    // New columns get their defaults
    expect((float) $row->debit)->toBe(0.0);
    expect((float) $row->credit)->toBe(0.0);
    expect($row->compte_id)->toBeNull();
    expect($row->tiers_id)->toBeNull();
    expect($row->lettrage_code)->toBeNull();
    expect($row->libelle)->toBeNull();
});

it('down migration removes all 6 new columns', function () {
    // Verify columns exist first (migration has been applied via RefreshDatabase)
    $newColumns = ['compte_id', 'debit', 'credit', 'tiers_id', 'lettrage_code', 'libelle'];
    foreach ($newColumns as $col) {
        expect(Schema::hasColumn('transaction_lignes', $col))->toBeTrue("Column {$col} should exist before down()");
    }

    // Run the down migration
    $migration = require base_path('database/migrations/2026_05_20_000004_add_partie_double_columns_to_transaction_lignes.php');
    $migration->down();

    // All 6 columns should be gone
    foreach ($newColumns as $col) {
        expect(Schema::hasColumn('transaction_lignes', $col))->toBeFalse("Column {$col} should be removed by down()");
    }

    // Legacy columns untouched
    expect(Schema::hasColumn('transaction_lignes', 'sous_categorie_id'))->toBeTrue();
    expect(Schema::hasColumn('transaction_lignes', 'montant'))->toBeTrue();
});
