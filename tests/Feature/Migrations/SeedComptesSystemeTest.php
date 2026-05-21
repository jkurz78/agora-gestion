<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\Compta\Migrations\SystemeSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Step 5 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Verifies that the SystemeSeeder inserts the system accounts 411, 401, 5112
 * (unconditionally per tenant) and 530 (conditionally — only when the tenant
 * has at least one non-deleted transaction with mode_paiement='especes').
 *
 * The seeder logic is extracted from the migration so it can be exercised here
 * without re-running the migration. Same pattern as AuditGuard / BancairesSeeder.
 */

/** Replays the système seed step (same SQL as the migration uses). */
function replaySystemeSeed(): void
{
    SystemeSeeder::seed();
}

it('creates 411, 401, 5112 for every tenant (2 tenants)', function () {
    $assoA = Association::firstOrFail();
    $assoB = Association::factory()->create();

    replaySystemeSeed();

    foreach ([$assoA, $assoB] as $asso) {
        foreach (['411', '401', '5112'] as $numero) {
            $compte = DB::table('comptes')
                ->where('association_id', $asso->id)
                ->where('numero_pcg', $numero)
                ->first();

            expect($compte)->not->toBeNull(
                "Compte {$numero} should exist for association {$asso->id}"
            );
        }
    }
});

it('does not create 530 when tenant has no espèces transactions', function () {
    $association = Association::firstOrFail();

    // Insert a virement transaction only — no espèces
    DB::table('transactions')->insert([
        'association_id' => $association->id,
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replaySystemeSeed();

    $compte = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '530')
        ->first();

    expect($compte)->toBeNull('Compte 530 should NOT be created when no espèces transactions exist');
});

it('creates 530 when tenant has at least one non-deleted espèces transaction', function () {
    $association = Association::firstOrFail();

    DB::table('transactions')->insert([
        'association_id' => $association->id,
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => '50.00',
        'mode_paiement' => 'especes',
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replaySystemeSeed();

    $compte = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '530')
        ->first();

    expect($compte)->not->toBeNull('Compte 530 should be created when tenant has a non-deleted espèces transaction');
});

it('does not create 530 when only soft-deleted espèces transactions exist', function () {
    $association = Association::firstOrFail();

    DB::table('transactions')->insert([
        'association_id' => $association->id,
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => '50.00',
        'mode_paiement' => 'especes',
        'deleted_at' => now(), // soft-deleted
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replaySystemeSeed();

    $compte = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '530')
        ->first();

    expect($compte)->toBeNull('Compte 530 should NOT be created when only soft-deleted espèces transactions exist');
});

it('530 conditional is per-tenant (A has espèces → 530 ; B no espèces → no 530)', function () {
    $assoA = Association::firstOrFail();
    $assoB = Association::factory()->create();

    // Tenant A: has a live espèces transaction
    DB::table('transactions')->insert([
        'association_id' => $assoA->id,
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => '50.00',
        'mode_paiement' => 'especes',
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Tenant B: only a virement
    DB::table('transactions')->insert([
        'association_id' => $assoB->id,
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replaySystemeSeed();

    $compte530A = DB::table('comptes')
        ->where('association_id', $assoA->id)
        ->where('numero_pcg', '530')
        ->first();

    $compte530B = DB::table('comptes')
        ->where('association_id', $assoB->id)
        ->where('numero_pcg', '530')
        ->first();

    expect($compte530A)->not->toBeNull('Tenant A with espèces should have compte 530');
    expect($compte530B)->toBeNull('Tenant B without espèces should NOT have compte 530');
});

it('system comptes have correct attributes (est_systeme, lettrable, categorie_id, actif, pour_inscriptions)', function () {
    $association = Association::firstOrFail();

    // Add espèces transaction so 530 is created
    DB::table('transactions')->insert([
        'association_id' => $association->id,
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => '50.00',
        'mode_paiement' => 'especes',
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replaySystemeSeed();

    $comptes = DB::table('comptes')
        ->where('association_id', $association->id)
        ->whereIn('numero_pcg', ['411', '401', '5112', '530'])
        ->get()
        ->keyBy('numero_pcg');

    foreach (['411', '401', '5112', '530'] as $numero) {
        $compte = $comptes->get($numero);
        expect($compte)->not->toBeNull("Compte {$numero} should exist");
        expect((bool) $compte->est_systeme)->toBeTrue("{$numero}: est_systeme should be true");
        expect((bool) $compte->lettrable)->toBeTrue("{$numero}: lettrable should be true");
        expect($compte->categorie_id)->toBeNull("{$numero}: categorie_id should be null");
        expect((bool) $compte->actif)->toBeTrue("{$numero}: actif should be true");
        expect((bool) $compte->pour_inscriptions)->toBeFalse("{$numero}: pour_inscriptions should be false");
        expect($compte->parent_compte_id)->toBeNull("{$numero}: parent_compte_id should be null");
        expect($compte->iban)->toBeNull("{$numero}: iban should be null");
        expect($compte->bic)->toBeNull("{$numero}: bic should be null");
        expect($compte->domiciliation)->toBeNull("{$numero}: domiciliation should be null");
        expect($compte->solde_initial)->toBeNull("{$numero}: solde_initial should be null");
        expect($compte->date_solde_initial)->toBeNull("{$numero}: date_solde_initial should be null");
    }

    // Classe checks
    expect((int) $comptes->get('411')->classe)->toBe(4, '411 should be classe 4');
    expect((int) $comptes->get('401')->classe)->toBe(4, '401 should be classe 4');
    expect((int) $comptes->get('5112')->classe)->toBe(5, '5112 should be classe 5');
    expect((int) $comptes->get('530')->classe)->toBe(5, '530 should be classe 5');
});

it('intituleds match spec (Clients / Fournisseurs / Chèques à encaisser / Caisse (espèces))', function () {
    $association = Association::firstOrFail();

    // Add espèces transaction so 530 is created
    DB::table('transactions')->insert([
        'association_id' => $association->id,
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => '50.00',
        'mode_paiement' => 'especes',
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replaySystemeSeed();

    $comptes = DB::table('comptes')
        ->where('association_id', $association->id)
        ->whereIn('numero_pcg', ['411', '401', '5112', '530'])
        ->get()
        ->keyBy('numero_pcg');

    expect($comptes->get('411')->intitule)->toBe('Clients');
    expect($comptes->get('401')->intitule)->toBe('Fournisseurs');
    expect($comptes->get('5112')->intitule)->toBe('Chèques à encaisser');
    expect($comptes->get('530')->intitule)->toBe('Caisse (espèces)');
});

it('is idempotent — running the seed twice produces no duplicates', function () {
    $association = Association::firstOrFail();

    DB::table('transactions')->insert([
        'association_id' => $association->id,
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => '50.00',
        'mode_paiement' => 'especes',
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replaySystemeSeed();
    replaySystemeSeed(); // second call must be a no-op

    foreach (['411', '401', '5112', '530'] as $numero) {
        $count = DB::table('comptes')
            ->where('association_id', $association->id)
            ->where('numero_pcg', $numero)
            ->count();

        expect($count)->toBe(1, "Compte {$numero} should appear exactly once after idempotent re-seed");
    }
});
