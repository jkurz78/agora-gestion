<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
 * Step 9 of plans/fondations-partie-double-slice1.md.
 *
 * Verifies the enriched App\Models\Compte model: static finders (ofNumero,
 * ofNumeroSysteme), scopes (lettrables, classe, bancaires), the lignes()
 * HasMany relation, tenant isolation inherited from TenantModel, and casts.
 *
 * Important test-environment note
 * --------------------------------
 * TenantContext is booted by the global Pest.php beforeEach with a freshly
 * created association (id N). The `association` table may also contain a
 * pre-existing row (id=1) inserted during the migration run. To avoid
 * ambiguity, all tests obtain the current tenant via TenantContext::current()
 * rather than Association::firstOrFail().
 *
 * System accounts (411/401/5112) are only seeded by SystemeSeeder::seed()
 * for associations that exist AT migration time. The factory association
 * created per-test is newer, so it has no system accounts by default.
 * Tests that need system accounts call SystemeSeeder::seed() directly.
 */

// ---------------------------------------------------------------------------
// 1. ofNumero — basic finder
// ---------------------------------------------------------------------------

it('ofNumero returns the compte for the current tenant', function () {
    $association = TenantContext::current();

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

    $compte = Compte::ofNumero('706');

    expect($compte)->not->toBeNull();
    expect($compte->numero_pcg)->toBe('706');
});

it('ofNumero returns null when the numero does not exist', function () {
    $result = Compte::ofNumero('999999');

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// 2. ofNumero respects tenant scope
// ---------------------------------------------------------------------------

it('ofNumero does not return a compte from a different tenant', function () {
    $assoA = TenantContext::current();
    $assoB = Association::factory()->create();

    // Insert a 706 for tenant B (tenant A has none)
    DB::table('comptes')->insert([
        'association_id' => $assoB->id,
        'numero_pcg' => '706',
        'intitule' => 'Prestations Tenant B',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Context is booted for tenant A — tenant A has no 706 → must return null
    $result = Compte::ofNumero('706');

    expect($result)->toBeNull();
});

it('ofNumero returns tenant A compte and not tenant B compte', function () {
    $assoA = TenantContext::current();
    $assoB = Association::factory()->create();

    DB::table('comptes')->insert([
        [
            'association_id' => $assoA->id,
            'numero_pcg' => '706',
            'intitule' => 'Prestations Tenant A',
            'classe' => 7,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'lettrable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'association_id' => $assoB->id,
            'numero_pcg' => '706',
            'intitule' => 'Prestations Tenant B',
            'classe' => 7,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'lettrable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Context is tenant A
    $compte = Compte::ofNumero('706');

    expect($compte)->not->toBeNull();
    expect($compte->intitule)->toBe('Prestations Tenant A');
});

// ---------------------------------------------------------------------------
// 3. ofNumeroSysteme — returns the system 411
// ---------------------------------------------------------------------------

it('ofNumeroSysteme returns the 411 compte and it is a system compte', function () {
    // Seed system accounts for the current tenant.
    SystemeSeeder::seed();

    $compte = Compte::ofNumeroSysteme('411');

    expect($compte)->not->toBeNull();
    expect($compte->numero_pcg)->toBe('411');
    expect($compte->est_systeme)->toBeTrue();
});

// ---------------------------------------------------------------------------
// 4. ofNumeroSysteme throws when the account is missing
// ---------------------------------------------------------------------------

it('ofNumeroSysteme throws ModelNotFoundException for an unknown numero', function () {
    Compte::ofNumeroSysteme('999');
})->throws(ModelNotFoundException::class);

// ---------------------------------------------------------------------------
// 5. ofNumeroSysteme does not match non-system rows
// ---------------------------------------------------------------------------

it('ofNumeroSysteme does not return a non-system row for 411', function () {
    $association = TenantContext::current();

    // Insert a non-system 411 without seeding the system row.
    DB::table('comptes')->insert([
        'association_id' => $association->id,
        'numero_pcg' => '411',
        'intitule' => 'Clients (non-système)',
        'classe' => 4,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // No est_systeme=true row exists → must throw.
    Compte::ofNumeroSysteme('411');
})->throws(ModelNotFoundException::class);

// ---------------------------------------------------------------------------
// 6. Scope lettrables
// ---------------------------------------------------------------------------

it('lettrables() scope returns only lettrable comptes', function () {
    $association = TenantContext::current();

    // Seed system accounts: 411, 401, 5112 (all lettrable = 1).
    SystemeSeeder::seed();

    // Insert a non-lettrable classe-7 compte.
    DB::table('comptes')->insert([
        'association_id' => $association->id,
        'numero_pcg' => '706',
        'intitule' => 'Prestations non-lettrable',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lettrables = Compte::lettrables()->get();

    // 411, 401, 5112 are lettrable; the 706 we inserted is not.
    expect($lettrables->count())->toBe(3);
    expect($lettrables->every(fn (Compte $c) => $c->lettrable === true))->toBeTrue();

    // The non-lettrable 706 must not appear.
    expect($lettrables->where('numero_pcg', '706')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 7. Scope classe(int)
// ---------------------------------------------------------------------------

it('classe(7) scope returns only classe-7 comptes', function () {
    $association = TenantContext::current();

    // Insert two classe-7 rows.
    DB::table('comptes')->insert([
        [
            'association_id' => $association->id,
            'numero_pcg' => '706',
            'intitule' => 'Prestations',
            'classe' => 7,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'lettrable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'association_id' => $association->id,
            'numero_pcg' => '758',
            'intitule' => 'Produits divers',
            'classe' => 7,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'lettrable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Seed system accounts — they are classe-4 (411, 401) and classe-5 (5112).
    SystemeSeeder::seed();

    $classe7 = Compte::classe(7)->get();

    expect($classe7->count())->toBe(2);
    expect($classe7->every(fn (Compte $c) => $c->classe === 7))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 8. Scope bancaires — positive assertions (11 banks)
// ---------------------------------------------------------------------------

it('bancaires() returns 11 comptes when 11 banks are seeded', function () {
    $association = TenantContext::current();

    $banks = [];
    for ($i = 1; $i <= 11; $i++) {
        $banks[] = [
            'association_id' => $association->id,
            'nom' => "Banque {$i}",
            'iban' => null,
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => '0.00',
            'date_solde_initial' => null,
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    DB::table('comptes_bancaires')->insert($banks);
    BancairesSeeder::seed();

    expect(Compte::bancaires()->count())->toBe(11);

    $numeros = Compte::bancaires()->orderBy('id')->pluck('numero_pcg')->all();
    expect($numeros)->toContain('51210');
    expect($numeros)->toContain('51211');
});

// ---------------------------------------------------------------------------
// 9. Scope bancaires — negative: 5112 and 530 excluded
// ---------------------------------------------------------------------------

it('bancaires() excludes 5112 (cheques a encaisser)', function () {
    $association = TenantContext::current();

    // Seed system accounts — 5112 will be present.
    SystemeSeeder::seed();

    // Verify 5112 exists so the negative assertion is meaningful.
    $has5112Raw = DB::table('comptes')
        ->where('association_id', $association->id)
        ->where('numero_pcg', '5112')
        ->exists();
    expect($has5112Raw)->toBeTrue();

    // bancaires() must exclude it.
    $exists = Compte::bancaires()->where('numero_pcg', '5112')->exists();

    expect($exists)->toBeFalse();
});

it('bancaires() excludes 530 (caisse especes)', function () {
    $association = TenantContext::current();

    // Insert a 530 row directly to be sure it exists.
    DB::table('comptes')->insertOrIgnore([
        'association_id' => $association->id,
        'numero_pcg' => '530',
        'intitule' => 'Caisse (espèces)',
        'classe' => 5,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
        'lettrable' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $exists = Compte::bancaires()->where('numero_pcg', '530')->exists();

    expect($exists)->toBeFalse();
});

// ---------------------------------------------------------------------------
// 10. lignes() HasMany relation
// ---------------------------------------------------------------------------

it('lignes() returns TransactionLignes belonging to the compte', function () {
    $association = TenantContext::current();

    $compteId = DB::table('comptes')->insertGetId([
        'association_id' => $association->id,
        'numero_pcg' => '706',
        'intitule' => 'Prestations',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Use factories to satisfy the non-nullable FKs on transaction_lignes.
    $transaction = Transaction::factory()->create(['association_id' => $association->id]);
    $sousCategorie = SousCategorie::factory()->create();

    DB::table('transaction_lignes')->insert([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCategorie->id,
        'compte_id' => $compteId,
        'montant' => '100.00',
    ]);

    $compte = Compte::find($compteId);

    expect($compte)->not->toBeNull();
    expect($compte->lignes)->toHaveCount(1);
    expect((int) $compte->lignes->first()->compte_id)->toBe($compteId);
});

// ---------------------------------------------------------------------------
// 11. Tenant scope inherited from TenantModel
// ---------------------------------------------------------------------------

it('Compte::all() only returns comptes for the current tenant', function () {
    $assoA = TenantContext::current();
    $assoB = Association::factory()->create();

    // Insert a classe-7 compte for each tenant.
    DB::table('comptes')->insert([
        [
            'association_id' => $assoA->id,
            'numero_pcg' => '706',
            'intitule' => 'Prestations Tenant A',
            'classe' => 7,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'lettrable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'association_id' => $assoB->id,
            'numero_pcg' => '706',
            'intitule' => 'Prestations Tenant B',
            'classe' => 7,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'lettrable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Context booted for tenant A
    $all = Compte::all();

    expect($all->every(fn (Compte $c) => (int) $c->association_id === (int) $assoA->id))->toBeTrue();
    expect($all->where('intitule', 'Prestations Tenant B')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 12. Casts work correctly
// ---------------------------------------------------------------------------

it('casts classe as int and boolean flags correctly', function () {
    $association = TenantContext::current();

    // Insert a compte directly (no factories yet).
    DB::table('comptes')->insert([
        'association_id' => $association->id,
        'numero_pcg' => '411',
        'intitule' => 'Clients',
        'classe' => 4,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
        'lettrable' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $compte = Compte::ofNumero('411');

    expect($compte)->not->toBeNull();
    expect($compte->classe)->toBeInt();
    expect($compte->actif)->toBeBool();
    expect($compte->est_systeme)->toBeBool();
    expect($compte->lettrable)->toBeBool();
});
