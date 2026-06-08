<?php

declare(strict_types=1);

/**
 * Chantier G — Tests du diagnostic non-échappement PD dans compta:smoke-test-v5.
 *
 * Test [D] : Tx avec lignes legacy + PD → exit 0 (pas comptée comme "sans PD")
 * Test [E] : Tx avec lignes legacy mais SANS PD → exit 1, détectée comme "sans PD"
 * Test [F] : Tx HelloAsso sans PD → source = "HelloAsso"
 * Test [G] : Tx sans tiers → raison = "tiers_id null"
 * Test [H] : Tx liée à adhésion → source = "Adhésion (wizard)"
 * Test [I] : --detail affiche le tableau détaillé
 */

use App\Models\Association;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helper : crée un contexte tenant minimal avec comptes système
// ---------------------------------------------------------------------------

function setupSansPdTenant(): Association
{
    $asso = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::clear();
    TenantContext::boot($asso);
    session(['current_association_id' => $asso->id]);

    SystemeSeeder::seed();

    return $asso;
}

/**
 * Crée une transaction avec une ligne legacy (sous_categorie_id renseigné)
 * mais SANS lignes PD (aucune ligne avec compte_id / debit / credit).
 *
 * @param  array<string, mixed>  $overrides
 */
function creerTxLegacySansPd(Association $asso, array $overrides = []): Transaction
{
    $data = array_merge([
        'association_id' => (int) $asso->id,
        'date' => now()->format('Y-m-d'),
        'type' => 'recette',
        'montant_total' => 100.00,
        'libelle' => 'Tx legacy sans PD',
        'mode_paiement' => 'virement',
        'type_ecriture' => 'normale',
    ], $overrides);

    $tx = Transaction::forceCreate($data);

    // Ligne legacy (sous_categorie_id non null, pas de compte_id/debit/credit)
    $sousCat = SousCategorie::factory()->create(['association_id' => (int) $asso->id]);

    TransactionLigne::forceCreate([
        'transaction_id' => (int) $tx->id,
        'sous_categorie_id' => (int) $sousCat->id,
        'montant' => (float) $data['montant_total'],
        'debit' => 0.0,
        'credit' => 0.0,
        'compte_id' => null,
    ]);

    return $tx;
}

/**
 * Crée une transaction avec une ligne legacy ET des lignes PD (compte_id renseigné).
 *
 * @param  array<string, mixed>  $overrides
 */
function creerTxAvecPd(Association $asso, array $overrides = []): Transaction
{
    $data = array_merge([
        'association_id' => (int) $asso->id,
        'date' => now()->format('Y-m-d'),
        'type' => 'recette',
        'montant_total' => 100.00,
        'libelle' => 'Tx avec PD',
        'mode_paiement' => 'virement',
        'type_ecriture' => 'normale',
        'equilibree' => true,
        'journal' => 'vente',
    ], $overrides);

    $tx = Transaction::forceCreate($data);

    $sousCat = SousCategorie::factory()->create(['association_id' => (int) $asso->id]);

    // Créer un compte classe 7 pour le test (les comptes système ne couvrent que 411/401/5112)
    $compte7 = Compte::forceCreate([
        'association_id' => (int) $asso->id,
        'numero_pcg' => '706',
        'intitule' => 'Prestations de services',
        'classe' => 7,
        'actif' => true,
        'est_systeme' => false,
        'lettrable' => false,
        'pour_inscriptions' => false,
    ]);

    $compte411 = Compte::where('association_id', (int) $asso->id)
        ->where('numero_pcg', '411')
        ->first();

    // Ligne legacy enrichie avec PD
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $tx->id,
        'sous_categorie_id' => (int) $sousCat->id,
        'montant' => 100.00,
        'debit' => 0.0,
        'credit' => 100.00,
        'compte_id' => (int) $compte7->id,
    ]);

    // Ligne PD-only (411 D)
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $tx->id,
        'sous_categorie_id' => null,
        'montant' => 0.0,
        'debit' => 100.00,
        'credit' => 0.0,
        'compte_id' => $compte411 ? (int) $compte411->id : null,
    ]);

    return $tx;
}

// ---------------------------------------------------------------------------
// Test [D] — Tx avec lignes legacy + PD → exit 0
// ---------------------------------------------------------------------------

test('[D] smoke-test-v5 : Tx avec PD complète → pas comptée comme sans PD, exit 0', function (): void {
    $asso = setupSansPdTenant();

    creerTxAvecPd($asso);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(0);
})->group('smoke_v5', 'chantier_g');

// ---------------------------------------------------------------------------
// Test [E] — Tx avec lignes legacy mais SANS PD → exit 1
// ---------------------------------------------------------------------------

test('[E] smoke-test-v5 : Tx legacy sans PD → exit 1, détectée', function (): void {
    $asso = setupSansPdTenant();

    creerTxLegacySansPd($asso);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(1)
        ->expectsOutputToContain('Tx sans PD');
})->group('smoke_v5', 'chantier_g');

// ---------------------------------------------------------------------------
// Test [F] — Tx HelloAsso sans PD → source = "HelloAsso"
// ---------------------------------------------------------------------------

test('[F] smoke-test-v5 : Tx HelloAsso sans PD → source HelloAsso', function (): void {
    $asso = setupSansPdTenant();

    creerTxLegacySansPd($asso, [
        'helloasso_order_id' => 12345,
        'libelle' => 'Don via HelloAsso',
    ]);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(1)
        ->expectsOutputToContain('HelloAsso');
})->group('smoke_v5', 'chantier_g');

// ---------------------------------------------------------------------------
// Test [G] — Tx sans tiers → raison = "tiers_id null"
// ---------------------------------------------------------------------------

test('[G] smoke-test-v5 : Tx sans tiers → raison tiers_id null', function (): void {
    $asso = setupSansPdTenant();

    creerTxLegacySansPd($asso, ['tiers_id' => null]);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id], '--detail' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('tiers_id null');
})->group('smoke_v5', 'chantier_g');

// ---------------------------------------------------------------------------
// Test [H] — Tx liée à adhésion → source = "Adhésion (wizard)"
// ---------------------------------------------------------------------------

test('[H] smoke-test-v5 : Tx adhésion sans PD → source Adhésion (wizard)', function (): void {
    $asso = setupSansPdTenant();

    $tiers = Tiers::factory()->create(['association_id' => (int) $asso->id]);
    $tx = creerTxLegacySansPd($asso, ['tiers_id' => (int) $tiers->id]);

    // Créer une adhésion liée à cette transaction
    DB::table('adhesions')->insert([
        'association_id' => (int) $asso->id,
        'tiers_id' => (int) $tiers->id,
        'transaction_id' => (int) $tx->id,
        'date_debut' => now()->format('Y-m-d'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(1)
        ->expectsOutputToContain('Adhésion (wizard)');
})->group('smoke_v5', 'chantier_g');

// ---------------------------------------------------------------------------
// Test [I] — --detail affiche le tableau détaillé
// ---------------------------------------------------------------------------

test('[I] smoke-test-v5 : --detail affiche le tableau avec ID et libellé', function (): void {
    $asso = setupSansPdTenant();

    creerTxLegacySansPd($asso, ['libelle' => 'Ma transaction test orpheline']);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id], '--detail' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Ma transaction test orpheline');
})->group('smoke_v5', 'chantier_g');
