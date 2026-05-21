<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Step 8 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Asserts the structural shape of the `lettrage_audit` table introduced by
 * 2026_05_20_000006_create_lettrage_audit_table.php (schema per spec §2.5).
 *
 * This table is append-only: only `created_at` (no `updated_at`), no soft-delete.
 * The Eloquent model and LettrageService are deferred to a later step.
 */

/** Returns a minimal valid lettrage_audit row for the given association + compte. */
function minimalAuditRow(int $associationId, int $compteId, ?int $userId = null): array
{
    return [
        'association_id' => $associationId,
        'action' => 'lettre',
        'lettrage_code' => 'L0001',
        'compte_id' => $compteId,
        'transaction_ligne_ids' => json_encode([1, 2, 3]),
        'user_id' => $userId,
        'motif' => null,
        'created_at' => now(),
    ];
}

/** Inserts a minimal compte row and returns its id. The numero_pcg is made unique via a prefix+suffix. */
function insertMinimalCompte(int $associationId, string $suffix = 'x'): int
{
    // Prefix 'T' keeps us out of real PCG ranges and avoids clashes with
    // the '411' system compte that may be seeded by other migration tests.
    return DB::table('comptes')->insertGetId([
        'association_id' => $associationId,
        'numero_pcg' => 'T'.$suffix,
        'intitule' => 'Clients test '.$suffix,
        'classe' => 4,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('table lettrage_audit exists', function () {
    expect(Schema::hasTable('lettrage_audit'))->toBeTrue();
});

it('has all expected columns', function () {
    $expectedColumns = [
        'id',
        'association_id',
        'action',
        'lettrage_code',
        'compte_id',
        'transaction_ligne_ids',
        'user_id',
        'motif',
        'created_at',
    ];

    expect(Schema::hasColumns('lettrage_audit', $expectedColumns))->toBeTrue();
});

it('has no updated_at column (append-only log)', function () {
    expect(Schema::hasColumn('lettrage_audit', 'updated_at'))->toBeFalse();
    expect(Schema::hasColumn('lettrage_audit', 'deleted_at'))->toBeFalse();
});

it('accepts action=lettre', function () {
    $association = Association::firstOrFail();
    $compteId = insertMinimalCompte($association->id);

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), ['action' => 'lettre'])
    );

    expect(DB::table('lettrage_audit')->where('action', 'lettre')->exists())->toBeTrue();
});

it('accepts action=delettre', function () {
    $association = Association::firstOrFail();
    $compteId = insertMinimalCompte($association->id, 'b');

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), ['action' => 'delettre', 'lettrage_code' => 'L0002'])
    );

    expect(DB::table('lettrage_audit')->where('action', 'delettre')->exists())->toBeTrue();
});

it('rejects invalid action value', function () {
    $association = Association::firstOrFail();
    $compteId = insertMinimalCompte($association->id, 'c');

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), ['action' => 'bogus', 'lettrage_code' => 'L0003'])
    );
})->throws(QueryException::class);

it('stores and round-trips transaction_ligne_ids as JSON', function () {
    $association = Association::firstOrFail();
    $compteId = insertMinimalCompte($association->id, 'd');

    $ids = [10, 20, 30];
    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), [
            'transaction_ligne_ids' => json_encode($ids),
            'lettrage_code' => 'L0010',
        ])
    );

    $row = DB::table('lettrage_audit')->where('lettrage_code', 'L0010')->first();
    expect($row)->not->toBeNull();
    expect(json_decode($row->transaction_ligne_ids, true))->toBe($ids);
});

it('accepts null user_id', function () {
    $association = Association::firstOrFail();
    $compteId = insertMinimalCompte($association->id, 'e');

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), ['user_id' => null, 'lettrage_code' => 'L0011'])
    );

    $row = DB::table('lettrage_audit')->where('lettrage_code', 'L0011')->first();
    expect($row->user_id)->toBeNull();
});

it('accepts null motif', function () {
    $association = Association::firstOrFail();
    $compteId = insertMinimalCompte($association->id, 'f');

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), ['motif' => null, 'lettrage_code' => 'L0012'])
    );

    $row = DB::table('lettrage_audit')->where('lettrage_code', 'L0012')->first();
    expect($row->motif)->toBeNull();
});

it('has the expected indexes on lettrage_audit', function () {
    $indexes = Schema::getIndexes('lettrage_audit');

    $byColumns = collect($indexes)->mapWithKeys(function ($index) {
        return [implode(',', $index['columns']) => $index];
    });

    expect($byColumns->has('association_id,lettrage_code'))->toBeTrue(
        'Missing index on (association_id, lettrage_code)'
    );
    expect($byColumns->has('association_id,created_at'))->toBeTrue(
        'Missing index on (association_id, created_at)'
    );
});

it('cascades on association deletion', function () {
    $association = Association::factory()->create();
    $compteId = insertMinimalCompte($association->id, 'g');

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), ['lettrage_code' => 'L0020'])
    );

    expect(DB::table('lettrage_audit')->where('lettrage_code', 'L0020')->exists())->toBeTrue();

    $association->forceDelete();

    expect(DB::table('lettrage_audit')->where('lettrage_code', 'L0020')->exists())->toBeFalse();
});

it('cascades on compte deletion', function () {
    $association = Association::firstOrFail();
    $compteId = insertMinimalCompte($association->id, 'h');

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), ['lettrage_code' => 'L0021'])
    );

    expect(DB::table('lettrage_audit')->where('lettrage_code', 'L0021')->exists())->toBeTrue();

    DB::table('comptes')->where('id', $compteId)->delete();

    expect(DB::table('lettrage_audit')->where('lettrage_code', 'L0021')->exists())->toBeFalse();
});

it('sets user_id to NULL when the linked user is deleted (nullOnDelete)', function () {
    $user = User::factory()->create();
    $association = Association::firstOrFail();
    $compteId = insertMinimalCompte($association->id, 'i');

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($association->id, $compteId), [
            'user_id' => $user->id,
            'lettrage_code' => 'L0022',
        ])
    );

    $row = DB::table('lettrage_audit')->where('lettrage_code', 'L0022')->first();
    expect((int) $row->user_id)->toBe((int) $user->id);

    $user->forceDelete();

    $rowAfter = DB::table('lettrage_audit')->where('lettrage_code', 'L0022')->first();
    expect($rowAfter)->not->toBeNull();
    expect($rowAfter->user_id)->toBeNull();
});

it('respects tenant scope — rows from tenant A are not visible when filtering by tenant B', function () {
    $assoA = Association::firstOrFail();
    $assoB = Association::factory()->create();
    $compteA = insertMinimalCompte($assoA->id, 'j');
    $compteB = insertMinimalCompte($assoB->id, 'k');

    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($assoA->id, $compteA), ['lettrage_code' => 'LA001'])
    );
    DB::table('lettrage_audit')->insert(
        array_merge(minimalAuditRow($assoB->id, $compteB), ['lettrage_code' => 'LB001'])
    );

    $rowsA = DB::table('lettrage_audit')->where('association_id', $assoA->id)->get();
    $rowsB = DB::table('lettrage_audit')->where('association_id', $assoB->id)->get();

    expect($rowsA->where('lettrage_code', 'LA001')->count())->toBe(1);
    expect($rowsA->where('lettrage_code', 'LB001')->count())->toBe(0);

    expect($rowsB->where('lettrage_code', 'LB001')->count())->toBe(1);
    expect($rowsB->where('lettrage_code', 'LA001')->count())->toBe(0);
});
