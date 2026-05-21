<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\Compte;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * Step 5 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Verifies that ComptePolicy::update() and ComptePolicy::delete() refuse
 * system accounts (est_systeme=true) regardless of user role, and allow
 * writes by Admin / Comptable on non-system accounts.
 */

afterEach(function (): void {
    TenantContext::clear();
});

// ── Helper: create a user attached to the given association with the given role ──

function makeCompteUser(Association $asso, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

// ── Helper: get or create a Compte row (no factory yet — deferred to Step 9) ──
//
// For system comptes (est_systeme=true), the migration 2026_05_20_000003 already
// seeded '411' for every tenant during RefreshDatabase. We load that existing row
// rather than inserting a duplicate that would violate the UNIQUE constraint.
//
// For non-system comptes, we insert a fresh row with a unique PCG derived from
// the test's random integer to avoid cross-test collisions within the same DB state.

function makeCompte(Association $asso, bool $estSysteme): Compte
{
    if ($estSysteme) {
        // For system comptes, insert OR retrieve '411' (est_systeme=true).
        // The SystemeSeeder may or may not have run for this tenant depending on
        // whether RefreshDatabase replayed the migration before or after the factory
        // association was created. We use insertOrIgnore to be safe in both cases.
        DB::table('comptes')->insertOrIgnore([
            'association_id' => $asso->id,
            'numero_pcg' => '411',
            'intitule' => 'Clients',
            'classe' => 4,
            'categorie_id' => null,
            'parent_compte_id' => null,
            'actif' => true,
            'est_systeme' => true,
            'pour_inscriptions' => false,
            'lettrable' => true,
            'iban' => null,
            'bic' => null,
            'domiciliation' => null,
            'solde_initial' => null,
            'date_solde_initial' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('comptes')
            ->where('association_id', $asso->id)
            ->where('numero_pcg', '411')
            ->first();

        return Compte::withoutGlobalScopes()->findOrFail($row->id);
    }

    // Non-system compte: use a PCG that won't collide with class-6/7 seeds
    // (the sous_categories seed is empty in RefreshDatabase — we own this number).
    $id = DB::table('comptes')->insertGetId([
        'association_id' => $asso->id,
        'numero_pcg' => '999',
        'intitule' => 'Compte test non-système',
        'classe' => 9,
        'categorie_id' => null,
        'parent_compte_id' => null,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'iban' => null,
        'bic' => null,
        'domiciliation' => null,
        'solde_initial' => null,
        'date_solde_initial' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Compte::withoutGlobalScopes()->findOrFail($id);
}

// ─────────────────────────────────────────────────────────────────────────────
// update() tests
// ─────────────────────────────────────────────────────────────────────────────

it('update() returns FALSE for est_systeme=true regardless of Admin role', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $admin = makeCompteUser($asso, RoleAssociation::Admin);
    $compte = makeCompte($asso, estSysteme: true);

    expect($admin->can('update', $compte))->toBeFalse();
});

it('update() returns FALSE for est_systeme=true for Comptable role', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $comptable = makeCompteUser($asso, RoleAssociation::Comptable);
    $compte = makeCompte($asso, estSysteme: true);

    expect($comptable->can('update', $compte))->toBeFalse();
});

it('update() returns TRUE for non-system compte for Admin (canWrite Compta)', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $admin = makeCompteUser($asso, RoleAssociation::Admin);
    $compte = makeCompte($asso, estSysteme: false);

    expect($admin->can('update', $compte))->toBeTrue();
});

it('update() returns TRUE for non-system compte for Comptable (canWrite Compta)', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $comptable = makeCompteUser($asso, RoleAssociation::Comptable);
    $compte = makeCompte($asso, estSysteme: false);

    expect($comptable->can('update', $compte))->toBeTrue();
});

it('update() returns FALSE for non-system compte for Consultation role (viewer-only)', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $viewer = makeCompteUser($asso, RoleAssociation::Consultation);
    $compte = makeCompte($asso, estSysteme: false);

    expect($viewer->can('update', $compte))->toBeFalse();
});

it('update() returns FALSE for non-system compte for Gestionnaire role (no Compta write)', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $gestionnaire = makeCompteUser($asso, RoleAssociation::Gestionnaire);
    $compte = makeCompte($asso, estSysteme: false);

    expect($gestionnaire->can('update', $compte))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// delete() tests
// ─────────────────────────────────────────────────────────────────────────────

it('delete() returns FALSE for est_systeme=true regardless of Admin role', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $admin = makeCompteUser($asso, RoleAssociation::Admin);
    $compte = makeCompte($asso, estSysteme: true);

    expect($admin->can('delete', $compte))->toBeFalse();
});

it('delete() returns FALSE for est_systeme=true for Comptable role', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $comptable = makeCompteUser($asso, RoleAssociation::Comptable);
    $compte = makeCompte($asso, estSysteme: true);

    expect($comptable->can('delete', $compte))->toBeFalse();
});

it('delete() returns TRUE for non-system compte for Admin', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $admin = makeCompteUser($asso, RoleAssociation::Admin);
    $compte = makeCompte($asso, estSysteme: false);

    expect($admin->can('delete', $compte))->toBeTrue();
});

it('delete() returns TRUE for non-system compte for Comptable', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $comptable = makeCompteUser($asso, RoleAssociation::Comptable);
    $compte = makeCompte($asso, estSysteme: false);

    expect($comptable->can('delete', $compte))->toBeTrue();
});

it('delete() returns FALSE for non-system compte for Consultation role (viewer-only)', function () {
    $asso = Association::firstOrFail();
    TenantContext::boot($asso);

    $viewer = makeCompteUser($asso, RoleAssociation::Consultation);
    $compte = makeCompte($asso, estSysteme: false);

    expect($viewer->can('delete', $compte))->toBeFalse();
});
