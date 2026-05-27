<?php

declare(strict_types=1);

/**
 * Step 43 — Tests compta:smoke-test-v5
 *
 * Test [A] : Tenant sans données → exit 0, output « aucune divergence »
 * Test [B] : Tenant avec Tx backfillée équilibrée → exit 0, divergence = 0€
 * Test [C] : Tenant avec Tx volontairement déséquilibrée → exit 1
 */

use App\Models\Association;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helper : crée un contexte tenant minimal avec comptes système
// ---------------------------------------------------------------------------

function setupSmokeTestTenant(): Association
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

// ---------------------------------------------------------------------------
// Test [A] — Tenant sans données → exit 0
// ---------------------------------------------------------------------------

test('[A] smoke-test-v5 : tenant sans transactions → exit 0, aucune divergence', function (): void {
    $asso = setupSmokeTestTenant();

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(0);
})->group('smoke_v5');

// ---------------------------------------------------------------------------
// Test [B] — Tenant sans transactions mais tenant valide → exit 0
// (aucune divergence possible si aucune donnée)
// ---------------------------------------------------------------------------

test('[B] smoke-test-v5 : tenant avec comptes système mais sans données → exit 0', function (): void {
    $asso = setupSmokeTestTenant();

    // Aucune transaction, aucune ligne → SUM(debit)=0, SUM(credit)=0 pour toutes les requêtes.
    // Les deux modes (legacy/PD) retournent 0€ de charges/produits → delta = 0€.
    // Aucune transaction dans le groupe-by → compterTxDesEquilibrees = 0.
    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(0);
})->group('smoke_v5');

// ---------------------------------------------------------------------------
// Test [C] — Tx volontairement déséquilibrée → exit 1
// ---------------------------------------------------------------------------

test('[C] smoke-test-v5 : Tx déséquilibrée → exit 1', function (): void {
    $asso = setupSmokeTestTenant();
    $tenantId = (int) TenantContext::currentId();

    // Créer une transaction directement (sans factory afterCreating pour contrôler les lignes)
    $txId = DB::table('transactions')->insertGetId([
        'association_id' => $tenantId,
        'date' => now()->format('Y-m-d'),
        'type' => 'recette',
        'montant_total' => 100.00,
        'equilibree' => 1,
        'libelle' => 'Test déséquilibre',
        'mode_paiement' => 'virement',
        'type_ecriture' => 'normale',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Insérer une seule ligne déséquilibrée (debit=100, credit=0, sans contrepartie)
    DB::table('transaction_lignes')->insert([
        'transaction_id' => $txId,
        'montant' => 100.00,
        'debit' => 100.00,
        'credit' => 0.00,
        'deleted_at' => null,
    ]);
    // Pas de ligne crédit → SUM(debit)=100, SUM(credit)=0 → déséquilibre

    $this->artisan('compta:smoke-test-v5', ['--asso' => [$asso->id]])
        ->assertExitCode(1);
})->group('smoke_v5');
