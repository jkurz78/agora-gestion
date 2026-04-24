<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\User;
use App\Tenant\TenantContext;

/*
 * Tests for NoteDeFraisPolicy::treat
 *
 * The global Pest.php beforeEach boots a default tenant.
 * Each test that needs a specific tenant boots it explicitly via
 * TenantContext::clear() + TenantContext::boot().
 */

// Helper: attach a user to an association with the given role.
function attachUserToAssociation(User $user, Association $association, RoleAssociation $role): void
{
    $user->associations()->attach($association->id, [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
}

// ── Test 1 : Admin in current tenant → treat true (class-level) ──────────────

it('allows Admin in current tenant to treat (class-level)', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);

    $user = User::factory()->create();
    attachUserToAssociation($user, $association, RoleAssociation::Admin);

    expect($user->can('treat', NoteDeFrais::class))->toBeTrue();
});

// ── Test 2 : Admin in current tenant + NDF of tenant → treat true (instance) ─

it('allows Admin in current tenant to treat a NDF instance from the same tenant', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);

    $user = User::factory()->create();
    attachUserToAssociation($user, $association, RoleAssociation::Admin);

    $ndf = NoteDeFrais::factory()->create(['association_id' => $association->id]);

    expect($user->can('treat', $ndf))->toBeTrue();
});

// ── Test 3 : Admin tenant A + NDF tenant A but TenantContext = B → false ─────

it('denies Admin of tenant A when TenantContext is booted on tenant B', function (): void {
    $tenantA = Association::factory()->create();
    $tenantB = Association::factory()->create();

    // Boot tenant B as current context
    TenantContext::clear();
    TenantContext::boot($tenantB);

    $user = User::factory()->create();
    attachUserToAssociation($user, $tenantA, RoleAssociation::Admin);

    // NDF belongs to tenant A, but context is tenant B
    $ndf = NoteDeFrais::factory()->create(['association_id' => $tenantA->id]);

    expect($user->can('treat', $ndf))->toBeFalse();
});

// ── Test 4 : Comptable in current tenant → treat true ────────────────────────

it('allows Comptable in current tenant to treat', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);

    $user = User::factory()->create();
    attachUserToAssociation($user, $association, RoleAssociation::Comptable);

    expect($user->can('treat', NoteDeFrais::class))->toBeTrue();
});

// ── Test 5 : Gestionnaire → false ────────────────────────────────────────────

it('denies Gestionnaire from treating', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);

    $user = User::factory()->create();
    attachUserToAssociation($user, $association, RoleAssociation::Gestionnaire);

    expect($user->can('treat', NoteDeFrais::class))->toBeFalse();
});

// ── Test 6 : Consultation → false ────────────────────────────────────────────

it('denies Consultation from treating', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);

    $user = User::factory()->create();
    attachUserToAssociation($user, $association, RoleAssociation::Consultation);

    expect($user->can('treat', NoteDeFrais::class))->toBeFalse();
});

// ── Test 7 : User not member of current association → false ──────────────────

it('denies User who is not a member of the current association', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);

    // User exists but has no membership in $association
    $user = User::factory()->create();

    expect($user->can('treat', NoteDeFrais::class))->toBeFalse();
});

// ── Test 8 : Guest (null user) → false ───────────────────────────────────────

it('denies unauthenticated access (null user)', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);

    // Gate::forUser(null) checks policy with null user
    $result = Gate::forUser(null)->check('treat', NoteDeFrais::class);

    expect($result)->toBeFalse();
});

// ── Test 9 : Revoked membership → false ──────────────────────────────────────

it('denies User whose membership has been revoked', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);

    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now()->subMonth(),
        'revoked_at' => now(),
    ]);

    expect($user->can('treat', NoteDeFrais::class))->toBeFalse();
});
