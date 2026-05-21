<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Step 7 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Asserts the structural shape of the columns added to `transactions`
 * by 2026_05_20_000005_add_equilibree_and_type_ecriture_to_transactions.php
 * (schema per spec §2.3).
 *
 * `equilibree` is a runtime invariant (∑débit = ∑crédit on lines) calculated
 * and verified at save time in a later step — no backfill is performed here.
 * `type_ecriture` values 'an' and 'od' are used by slice 2+ (à-nouveau,
 * opération diverse); 'extourne' aligns with the existing Extourne mechanism.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

it('has the equilibree column on transactions', function () {
    expect(Schema::hasColumn('transactions', 'equilibree'))->toBeTrue(
        'Column equilibree should exist on transactions'
    );
});

it('has the type_ecriture column on transactions', function () {
    expect(Schema::hasColumn('transactions', 'type_ecriture'))->toBeTrue(
        'Column type_ecriture should exist on transactions'
    );
});

it('equilibree defaults to false when not provided', function () {
    $id = DB::table('transactions')->insertGetId([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => now()->toDateString(),
        'libelle' => 'Test équilibrée default',
        'montant_total' => 50.00,
        'mode_paiement' => 'virement',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('transactions')->find($id);

    expect((bool) $row->equilibree)->toBeFalse();
});

it('type_ecriture defaults to normale when not provided', function () {
    $id = DB::table('transactions')->insertGetId([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => now()->toDateString(),
        'libelle' => 'Test type_ecriture default',
        'montant_total' => 75.00,
        'mode_paiement' => 'virement',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('transactions')->find($id);

    expect($row->type_ecriture)->toBe('normale');
});

it('type_ecriture accepts the value normale', function () {
    $id = DB::table('transactions')->insertGetId([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => now()->toDateString(),
        'libelle' => 'Test normale',
        'montant_total' => 10.00,
        'mode_paiement' => 'cheque',
        'type_ecriture' => 'normale',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('transactions')->find($id);
    expect($row->type_ecriture)->toBe('normale');
});

it('type_ecriture accepts the value an', function () {
    $id = DB::table('transactions')->insertGetId([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => now()->toDateString(),
        'libelle' => 'Test an',
        'montant_total' => 10.00,
        'mode_paiement' => 'cheque',
        'type_ecriture' => 'an',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('transactions')->find($id);
    expect($row->type_ecriture)->toBe('an');
});

it('type_ecriture accepts the value od', function () {
    $id = DB::table('transactions')->insertGetId([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => now()->toDateString(),
        'libelle' => 'Test od',
        'montant_total' => 10.00,
        'mode_paiement' => 'especes',
        'type_ecriture' => 'od',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('transactions')->find($id);
    expect($row->type_ecriture)->toBe('od');
});

it('type_ecriture accepts the value extourne', function () {
    $id = DB::table('transactions')->insertGetId([
        'association_id' => $this->association->id,
        'type' => 'depense',
        'date' => now()->toDateString(),
        'libelle' => 'Test extourne',
        'montant_total' => 10.00,
        'mode_paiement' => 'virement',
        'type_ecriture' => 'extourne',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('transactions')->find($id);
    expect($row->type_ecriture)->toBe('extourne');
});

it('type_ecriture rejects an invalid value', function () {
    DB::table('transactions')->insertGetId([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => now()->toDateString(),
        'libelle' => 'Test bogus',
        'montant_total' => 10.00,
        'mode_paiement' => 'virement',
        'type_ecriture' => 'bogus',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('legacy column type is still present', function () {
    expect(Schema::hasColumn('transactions', 'type'))->toBeTrue(
        'Legacy column type should still exist on transactions'
    );
});

it('legacy column compte_id is still present', function () {
    expect(Schema::hasColumn('transactions', 'compte_id'))->toBeTrue(
        'Legacy column compte_id should still exist on transactions'
    );
});

it('existing rows survive the migration with correct new defaults', function () {
    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'montant_total' => 200.00,
        'libelle' => 'Ligne existante avant migration',
    ]);

    $row = DB::table('transactions')->find($transaction->id);

    // Legacy fields intact
    expect($row)->not->toBeNull();
    expect((int) $row->association_id)->toBe((int) $this->association->id);
    expect($row->libelle)->toBe('Ligne existante avant migration');
    expect((float) $row->montant_total)->toBe(200.00);

    // New defaults applied
    expect((bool) $row->equilibree)->toBeFalse();
    expect($row->type_ecriture)->toBe('normale');
});

it('down migration removes equilibree and type_ecriture but keeps legacy columns', function () {
    // Verify new columns exist first (applied via RefreshDatabase)
    expect(Schema::hasColumn('transactions', 'equilibree'))->toBeTrue(
        'Column equilibree should exist before down()'
    );
    expect(Schema::hasColumn('transactions', 'type_ecriture'))->toBeTrue(
        'Column type_ecriture should exist before down()'
    );

    // Run the down migration
    $migration = require base_path('database/migrations/2026_05_20_000005_add_equilibree_and_type_ecriture_to_transactions.php');
    $migration->down();

    // New columns must be gone
    expect(Schema::hasColumn('transactions', 'equilibree'))->toBeFalse(
        'Column equilibree should be removed by down()'
    );
    expect(Schema::hasColumn('transactions', 'type_ecriture'))->toBeFalse(
        'Column type_ecriture should be removed by down()'
    );

    // Legacy columns untouched
    expect(Schema::hasColumn('transactions', 'type'))->toBeTrue();
    expect(Schema::hasColumn('transactions', 'compte_id'))->toBeTrue();
    expect(Schema::hasColumn('transactions', 'montant_total'))->toBeTrue();
    expect(Schema::hasColumn('transactions', 'tiers_id'))->toBeTrue();
});
