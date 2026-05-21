<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\Compta\Migrations\BancairesSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Step 4 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Verifies that the BancairesSeeder inserts classe-5 sous-comptes (5121, 5122…)
 * into `comptes` from existing `comptes_bancaires` rows, with correct numbering
 * per tenant, bank attributes copied verbatim, and idempotent replay.
 *
 * The seeder logic is extracted from the migration so it can be exercised here
 * against freshly inserted fixtures without re-running the migration. The pattern
 * mirrors the AuditGuard / replayComptesSeed() approach from Step 3.
 */

/** Replays the bancaires seed step (same SQL as the migration uses). */
function replayBancairesSeed(): void
{
    BancairesSeeder::seed();
}

it('assigns numero_pcg 5121 to the first bank of a tenant', function () {
    $association = Association::firstOrFail();

    DB::table('comptes_bancaires')->insert([
        'association_id' => $association->id,
        'nom' => 'Crédit Mutuel Courant',
        'iban' => 'FR7610278123456789012345601',
        'bic' => 'CMCIFR2A',
        'domiciliation' => 'Agence Paris Centre',
        'solde_initial' => '1500.00',
        'date_solde_initial' => '2025-01-01',
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replayBancairesSeed();

    $compte = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '5121')
        ->first();

    expect($compte)->not->toBeNull();
    expect($compte->intitule)->toBe('Crédit Mutuel Courant');
});

it('increments numero_pcg per tenant (3 banks → 5121, 5122, 5123)', function () {
    $association = Association::firstOrFail();

    // Insert 3 banks with predictable IDs (insert in order, rely on id ASC)
    DB::table('comptes_bancaires')->insert([
        [
            'association_id' => $association->id,
            'nom' => 'Banque A',
            'iban' => null,
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => '0.00',
            'date_solde_initial' => null,
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'association_id' => $association->id,
            'nom' => 'Banque B',
            'iban' => null,
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => '0.00',
            'date_solde_initial' => null,
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'association_id' => $association->id,
            'nom' => 'Banque C',
            'iban' => null,
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => '0.00',
            'date_solde_initial' => null,
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    replayBancairesSeed();

    $numeros = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('classe', 5)
        ->orderBy('numero_pcg')
        ->pluck('numero_pcg')
        ->all();

    expect($numeros)->toBe(['5121', '5122', '5123']);
});

it('restarts numbering at 5121 for each tenant', function () {
    $assoA = Association::firstOrFail();
    $assoB = Association::factory()->create();

    // Tenant A: 2 banks
    DB::table('comptes_bancaires')->insert([
        [
            'association_id' => $assoA->id,
            'nom' => 'Banque A1',
            'iban' => null,
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => '0.00',
            'date_solde_initial' => null,
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'association_id' => $assoA->id,
            'nom' => 'Banque A2',
            'iban' => null,
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => '0.00',
            'date_solde_initial' => null,
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Tenant B: 1 bank
    DB::table('comptes_bancaires')->insert([
        'association_id' => $assoB->id,
        'nom' => 'Banque B1',
        'iban' => null,
        'bic' => null,
        'domiciliation' => null,
        'solde_initial' => '0.00',
        'date_solde_initial' => null,
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replayBancairesSeed();

    $numerosA = DB::table('comptes')
        ->where('association_id', $assoA->id)
        ->where('classe', 5)
        ->orderBy('numero_pcg')
        ->pluck('numero_pcg')
        ->all();

    $numerosB = DB::table('comptes')
        ->where('association_id', $assoB->id)
        ->where('classe', 5)
        ->orderBy('numero_pcg')
        ->pluck('numero_pcg')
        ->all();

    expect($numerosA)->toBe(['5121', '5122']);
    expect($numerosB)->toBe(['5121']);
});

it('orders numbering by comptes_bancaires.id ASC (not by name)', function () {
    $association = Association::firstOrFail();

    // Insert in reverse alphabetical name order — id ordering should dominate
    $idC = DB::table('comptes_bancaires')->insertGetId([
        'association_id' => $association->id,
        'nom' => 'Zzz Bank (inserted first, lowest id)',
        'iban' => null,
        'bic' => null,
        'domiciliation' => null,
        'solde_initial' => '0.00',
        'date_solde_initial' => null,
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $idD = DB::table('comptes_bancaires')->insertGetId([
        'association_id' => $association->id,
        'nom' => 'Aaa Bank (inserted second, higher id)',
        'iban' => null,
        'bic' => null,
        'domiciliation' => null,
        'solde_initial' => '0.00',
        'date_solde_initial' => null,
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($idC)->toBeLessThan($idD);

    replayBancairesSeed();

    $compteZzz = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('intitule', 'Zzz Bank (inserted first, lowest id)')
        ->first();

    $compteAaa = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('intitule', 'Aaa Bank (inserted second, higher id)')
        ->first();

    expect($compteZzz)->not->toBeNull();
    expect($compteAaa)->not->toBeNull();
    // Zzz has lower id → gets 5121; Aaa has higher id → gets 5122
    expect($compteZzz->numero_pcg)->toBe('5121');
    expect($compteAaa->numero_pcg)->toBe('5122');
});

it('copies bank attributes verbatim (IBAN, BIC, domiciliation, solde_initial, date_solde_initial)', function () {
    $association = Association::firstOrFail();

    DB::table('comptes_bancaires')->insert([
        'association_id' => $association->id,
        'nom' => 'LCL Entreprises',
        'iban' => 'FR7630002123456789012345678',
        'bic' => 'CRLYFRPP',
        'domiciliation' => 'LCL Lyon Part-Dieu',
        'solde_initial' => '4250.50',
        'date_solde_initial' => '2025-09-01',
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replayBancairesSeed();

    $compte = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '5121')
        ->first();

    expect($compte)->not->toBeNull();
    expect($compte->iban)->toBe('FR7630002123456789012345678');
    expect($compte->bic)->toBe('CRLYFRPP');
    expect($compte->domiciliation)->toBe('LCL Lyon Part-Dieu');
    expect((float) $compte->solde_initial)->toBe(4250.50);
    expect($compte->date_solde_initial)->toBe('2025-09-01');
});

it('sets expected default flags on seeded bank comptes', function () {
    $association = Association::firstOrFail();

    DB::table('comptes_bancaires')->insert([
        'association_id' => $association->id,
        'nom' => 'BNP Paribas',
        'iban' => null,
        'bic' => null,
        'domiciliation' => null,
        'solde_initial' => '0.00',
        'date_solde_initial' => null,
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replayBancairesSeed();

    $compte = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '5121')
        ->first();

    expect($compte)->not->toBeNull();
    expect((int) $compte->classe)->toBe(5);
    expect((bool) $compte->est_systeme)->toBeTrue();
    expect((bool) $compte->lettrable)->toBeFalse();
    expect((bool) $compte->pour_inscriptions)->toBeFalse();
    expect((bool) $compte->actif)->toBeTrue();
    expect($compte->categorie_id)->toBeNull();
    expect($compte->parent_compte_id)->toBeNull();
});

it('does not seed soft-deleted comptes_bancaires rows', function () {
    // NOTE: comptes_bancaires has no deleted_at column (no SoftDeletes on the model).
    // This test verifies the documented behaviour: since the table has no deleted_at,
    // ALL rows are included. The "skip soft-deleted" requirement is vacuously satisfied
    // (there are no soft-deleted rows to skip). We test that the seeder does not
    // introduce a spurious WHERE deleted_at IS NULL that would fail on the live schema.
    $association = Association::firstOrFail();

    DB::table('comptes_bancaires')->insert([
        'association_id' => $association->id,
        'nom' => 'Compte Actif',
        'iban' => null,
        'bic' => null,
        'domiciliation' => null,
        'solde_initial' => '0.00',
        'date_solde_initial' => null,
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replayBancairesSeed();

    $count = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('classe', 5)
        ->count();

    expect($count)->toBe(1);
});

it('does not leak tenant A comptes into tenant B scope', function () {
    $assoA = Association::firstOrFail();
    $assoB = Association::factory()->create();

    DB::table('comptes_bancaires')->insert([
        [
            'association_id' => $assoA->id,
            'nom' => 'Banque Tenant A',
            'iban' => 'FR7610278000000000000000001',
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => '0.00',
            'date_solde_initial' => null,
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'association_id' => $assoB->id,
            'nom' => 'Banque Tenant B',
            'iban' => 'FR7610278000000000000000002',
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => '0.00',
            'date_solde_initial' => null,
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    replayBancairesSeed();

    $countA = DB::table('comptes')
        ->where('association_id', $assoA->id)
        ->where('classe', 5)
        ->count();

    $countB = DB::table('comptes')
        ->where('association_id', $assoB->id)
        ->where('classe', 5)
        ->count();

    // Each tenant has exactly 1 bank compte; no cross-tenant spill
    expect($countA)->toBe(1);
    expect($countB)->toBe(1);

    $compteA = DB::table('comptes')
        ->where('association_id', $assoA->id)
        ->where('classe', 5)
        ->first();

    $compteB = DB::table('comptes')
        ->where('association_id', $assoB->id)
        ->where('classe', 5)
        ->first();

    expect($compteA->intitule)->toBe('Banque Tenant A');
    expect($compteB->intitule)->toBe('Banque Tenant B');
    expect($compteA->iban)->not->toBe($compteB->iban);
});

it('is idempotent — re-running the seed is a no-op', function () {
    $association = Association::firstOrFail();

    DB::table('comptes_bancaires')->insert([
        'association_id' => $association->id,
        'nom' => 'Banque Idempotente',
        'iban' => null,
        'bic' => null,
        'domiciliation' => null,
        'solde_initial' => '0.00',
        'date_solde_initial' => null,
        'actif_recettes_depenses' => true,
        'saisie_automatisee' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    replayBancairesSeed();
    replayBancairesSeed(); // second call must be a no-op

    $count = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('classe', 5)
        ->count();

    expect($count)->toBe(1);
});
