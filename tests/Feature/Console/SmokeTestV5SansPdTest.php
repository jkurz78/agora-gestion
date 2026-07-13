<?php

declare(strict_types=1);

/**
 * Chantier G — Tests du diagnostic non-échappement PD dans compta:smoke-test-v5.
 *
 * Test [D] : Tx avec ventilation + PD → exit 0 (pas comptée comme "sans PD")
 * Test [E] : Tx avec ventilation mais SANS PD → exit 1, détectée comme "sans PD"
 * Test [F] : Tx HelloAsso sans PD → source = "HelloAsso"
 * Test [G] : Tx sans tiers → raison = "tiers_id null"
 * Test [H] : Tx liée à adhésion → source = "Adhésion (wizard)"
 * Test [I] : --detail affiche le tableau détaillé
 */

use App\Models\Association;
use App\Models\Compte;
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
 * Crée une transaction avec une ventilation 6/7 mais SANS écriture PD
 * (débit et crédit à zéro).
 *
 * @param  array<string, mixed>  $overrides
 */
function creerTxVentilationSansPd(Association $asso, array $overrides = []): Transaction
{
    $data = array_merge([
        'association_id' => (int) $asso->id,
        'date' => now()->format('Y-m-d'),
        'type' => 'recette',
        'montant_total' => 100.00,
        'libelle' => 'Tx avec ventilation sans PD',
        'mode_paiement' => 'virement',
        'type_ecriture' => 'normale',
    ], $overrides);

    $tx = Transaction::forceCreate($data);

    $compteVentilation = Compte::factory()->numero('706')->create([
        'association_id' => (int) $asso->id,
        'intitule' => 'Prestations de services',
    ]);

    // Bypass volontaire de l'observer : cette fixture représente précisément
    // l'anomalie historique que la commande doit diagnostiquer.
    DB::table('transaction_lignes')->insert([
        'transaction_id' => (int) $tx->id,
        'compte_id' => (int) $compteVentilation->id,
        'montant' => (float) $data['montant_total'],
        'debit' => 0.0,
        'credit' => 0.0,
    ]);

    return $tx;
}

/**
 * Crée une transaction avec une ventilation ET ses lignes PD.
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

    // Ligne de ventilation enrichie avec PD
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $tx->id,
        'montant' => 100.00,
        'debit' => 0.0,
        'credit' => 100.00,
        'compte_id' => (int) $compte7->id,
    ]);

    // Ligne PD-only (411 D)
    TransactionLigne::forceCreate([
        'transaction_id' => (int) $tx->id,
        'montant' => 0.0,
        'debit' => 100.00,
        'credit' => 0.0,
        'compte_id' => $compte411 ? (int) $compte411->id : null,
    ]);

    return $tx;
}

// ---------------------------------------------------------------------------
// Test [D] — Tx avec ventilation + PD → exit 0
// ---------------------------------------------------------------------------

test('[D] smoke-test-v5 : Tx avec PD complète → pas comptée comme sans PD, exit 0', function (): void {
    $asso = setupSansPdTenant();

    creerTxAvecPd($asso);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(0);
})->group('smoke_v5', 'chantier_g');

// ---------------------------------------------------------------------------
// Test [E] — Tx avec ventilation mais SANS PD → exit 1
// ---------------------------------------------------------------------------

test('[E] smoke-test-v5 : Tx avec ventilation sans PD → exit 1, détectée', function (): void {
    $asso = setupSansPdTenant();

    creerTxVentilationSansPd($asso);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(1)
        ->expectsOutputToContain('Tx sans PD');
})->group('smoke_v5', 'chantier_g');

// ---------------------------------------------------------------------------
// Test [F] — Tx HelloAsso sans PD → source = "HelloAsso"
// ---------------------------------------------------------------------------

test('[F] smoke-test-v5 : Tx HelloAsso sans PD → source HelloAsso', function (): void {
    $asso = setupSansPdTenant();

    creerTxVentilationSansPd($asso, [
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

    creerTxVentilationSansPd($asso, ['tiers_id' => null]);

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
    $tx = creerTxVentilationSansPd($asso, ['tiers_id' => (int) $tiers->id]);

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

    creerTxVentilationSansPd($asso, ['libelle' => 'Ma transaction test orpheline']);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id], '--detail' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Ma transaction test orpheline');
})->group('smoke_v5', 'chantier_g');

// ---------------------------------------------------------------------------
// Test [J] — Tx à 0 € (cotisation offerte par code promo) → hors périmètre PD
// ---------------------------------------------------------------------------

test('[J] smoke-test-v5 : Tx à 0 € sans PD → exemptée par design, exit 0', function (): void {
    $asso = setupSansPdTenant();

    // Miroir de l'exemption TransactionConverter::convertir() : montant_total = 0
    // (ex. cotisation HelloAsso offerte par code promo) → aucune écriture PD possible
    // ni souhaitable. Le smoke test ne doit pas la compter comme échappée.
    creerTxVentilationSansPd($asso, [
        'montant_total' => 0.00,
        'helloasso_order_id' => 99999,
        'libelle' => 'HelloAsso — cotisation offerte par code promo',
    ]);

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(0);
})->group('smoke_v5', 'chantier_g');
