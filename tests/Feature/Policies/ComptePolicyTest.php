<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\Compte;
use App\Models\User;
use App\Tenant\TenantContext;

/*
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

// ── Helper: get or create a Compte row ──

function makeCompte(Association $asso, bool $estSysteme): Compte
{
    if ($estSysteme) {
        return Compte::withoutGlobalScopes()->firstOrCreate(
            [
                'association_id' => (int) $asso->id,
                'numero_pcg' => '411',
            ],
            [
                'intitule' => 'Clients',
                'classe' => 4,
                'actif' => true,
                'est_systeme' => true,
                'pour_inscriptions' => false,
                'lettrable' => true,
            ],
        );
    }

    return Compte::factory()->numero('999')->create([
        'association_id' => (int) $asso->id,
        'classe' => 9,
        'est_systeme' => false,
    ]);
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
