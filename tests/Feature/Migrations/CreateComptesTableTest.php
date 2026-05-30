<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Categorie;
use App\Services\Compta\Migrations\AuditGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Step 3 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Asserts the structural shape of the `comptes` table introduced by
 * 2026_05_20_000001_create_comptes_table.php (schema per spec §2.1)
 * and the seeding of comptes from existing `sous_categories` rows.
 *
 * The migration also enforces a pre-check guard: if any sous_categorie
 * has NULL code_cerfa at migrate time, the migration aborts with a
 * RuntimeException pointing to the audit:compta-v5-preparation command.
 * That guard is implemented as a testable utility class
 * (App\Services\Compta\Migrations\AuditGuard) and exercised here against
 * a live DB without having to roll back / re-run the migration.
 *
 * Seeding tests use the same idempotent INSERT…SELECT statement as the
 * migration's seed step (see AuditGuard::seedFromSousCategoriesSql) so
 * that any sous_categorie created after the migration was applied can
 * be flushed into comptes without re-running the migration.
 */

/** Replays the migration's seed step. */
function replayComptesSeed(): void
{
    DB::statement(AuditGuard::seedFromSousCategoriesSql());
}

it('creates comptes table with all expected columns', function () {
    $expectedColumns = [
        'id',
        'association_id',
        'numero_pcg',
        'intitule',
        'classe',
        'categorie_id',
        'parent_compte_id',
        'actif',
        'est_systeme',
        'pour_inscriptions',
        'lettrable',
        'iban',
        'bic',
        'domiciliation',
        'solde_initial',
        'date_solde_initial',
        'compte_bancaire_id',
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('comptes', $column))->toBeTrue(
            "Column {$column} should exist on comptes"
        );
    }
});

it('enforces unique constraint on association_id + numero_pcg', function () {
    $association = Association::firstOrFail();

    DB::table('comptes')->insert([
        'association_id' => $association->id,
        'numero_pcg' => '706',
        'intitule' => 'Prestations de services',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('comptes')->insert([
        'association_id' => $association->id,
        'numero_pcg' => '706',
        'intitule' => 'Doublon',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('has the expected indexes on comptes', function () {
    $indexes = Schema::getIndexes('comptes');

    // Build a map keyed by sorted column tuple → metadata for easy lookup.
    $byColumns = collect($indexes)->mapWithKeys(function ($index) {
        return [implode(',', $index['columns']) => $index];
    });

    expect($byColumns->has('association_id,numero_pcg'))->toBeTrue('Missing UNIQUE index on (association_id, numero_pcg)');
    expect($byColumns->has('association_id,classe'))->toBeTrue('Missing index on (association_id, classe)');
    expect($byColumns->has('association_id,lettrable'))->toBeTrue('Missing index on (association_id, lettrable)');

    expect($byColumns['association_id,numero_pcg']['unique'])->toBeTrue(
        'Index on (association_id, numero_pcg) must be UNIQUE'
    );
});

it('seeds comptes from sous_categories with the expected attributes', function () {
    // RefreshDatabase produces a clean schema with no sous_categories, so we
    // exercise the seed path by inserting a sous_categorie and replaying the
    // same INSERT…SELECT used by the migration.
    $association = Association::firstOrFail();

    $categorie = Categorie::create([
        'association_id' => $association->id,
        'nom' => 'Prestations',
        'type' => 'recette',
    ]);

    DB::table('sous_categories')->insert([
        'association_id' => $association->id,
        'categorie_id' => $categorie->id,
        'nom' => 'Vente de services',
        'code_cerfa' => '706',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replayComptesSeed();

    $compte = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '706')
        ->first();

    expect($compte)->not->toBeNull();
    expect($compte->intitule)->toBe('Vente de services');
    expect((int) $compte->classe)->toBe(7);
    expect((int) $compte->categorie_id)->toBe($categorie->id);
    expect((bool) $compte->pour_inscriptions)->toBeFalse();
    expect((bool) $compte->lettrable)->toBeFalse();
    expect((bool) $compte->est_systeme)->toBeFalse();
});

it('blocks migration when a sous_categorie has no code_cerfa', function () {
    $assoId = Association::firstOrFail()->id;
    $catId = Categorie::create([
        'association_id' => $assoId,
        'nom' => 'X',
        'type' => 'recette',
    ])->id;

    DB::table('sous_categories')->insert([
        'association_id' => $assoId,
        'categorie_id' => $catId,
        'nom' => 'Cassée',
        'code_cerfa' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    AuditGuard::assertAuditPassed();
})
    ->throws(
        RuntimeException::class,
        'Run `php artisan audit:compta-v5-preparation` first and fix sous-catégories without code_cerfa before migrating'
    );

it('lets the audit guard pass when every sous_categorie has a code_cerfa', function () {
    // Default suite state: no sous_categorie present at all → guard passes.
    expect(DB::table('sous_categories')->whereNull('code_cerfa')->exists())->toBeFalse();

    AuditGuard::assertAuditPassed();
    expect(true)->toBeTrue();
});

it('respects tenant scope on seeded comptes (no cross-tenant leak)', function () {
    $assoA = Association::firstOrFail();
    $assoB = Association::factory()->create();

    $catA = Categorie::create([
        'association_id' => $assoA->id,
        'nom' => 'Prestations A',
        'type' => 'recette',
    ]);
    $catB = Categorie::create([
        'association_id' => $assoB->id,
        'nom' => 'Prestations B',
        'type' => 'recette',
    ]);

    DB::table('sous_categories')->insert([
        [
            'association_id' => $assoA->id,
            'categorie_id' => $catA->id,
            'nom' => 'Vente A',
            'code_cerfa' => '707',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'association_id' => $assoB->id,
            'categorie_id' => $catB->id,
            'nom' => 'Vente B',
            'code_cerfa' => '707',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    replayComptesSeed();

    $compteA = DB::table('comptes')
        ->where('association_id', $assoA->id)
        ->where('numero_pcg', '707')
        ->first();
    $compteB = DB::table('comptes')
        ->where('association_id', $assoB->id)
        ->where('numero_pcg', '707')
        ->first();

    expect($compteA)->not->toBeNull();
    expect($compteB)->not->toBeNull();
    expect($compteA->id)->not->toBe($compteB->id);
    expect((int) $compteA->categorie_id)->toBe($catA->id);
    expect((int) $compteB->categorie_id)->toBe($catB->id);
});

it('seeds pour_inscriptions=true when sous_categorie has usage inscription', function () {
    $association = Association::firstOrFail();

    $categorie = Categorie::create([
        'association_id' => $association->id,
        'nom' => 'Inscriptions',
        'type' => 'recette',
    ]);

    $scId = DB::table('sous_categories')->insertGetId([
        'association_id' => $association->id,
        'categorie_id' => $categorie->id,
        'nom' => 'Stages',
        'code_cerfa' => '708',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('usages_sous_categories')->insert([
        'association_id' => $association->id,
        'sous_categorie_id' => $scId,
        'usage' => 'inscription',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replayComptesSeed();

    $compte = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '708')
        ->first();

    expect($compte)->not->toBeNull();
    expect((bool) $compte->pour_inscriptions)->toBeTrue();
});
