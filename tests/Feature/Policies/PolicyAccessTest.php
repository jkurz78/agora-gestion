<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helper: create a user with a given role in the current association context.
function makeUserWithRole(RoleAssociation $role, Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ── Operation (Gestion espace) ──

it('admin can create operations', function () {
    $user = makeUserWithRole(RoleAssociation::Admin, $this->association);
    expect($user->can('create', Operation::class))->toBeTrue();
});

it('gestionnaire can create operations', function () {
    $user = makeUserWithRole(RoleAssociation::Gestionnaire, $this->association);
    expect($user->can('create', Operation::class))->toBeTrue();
});

it('comptable cannot create operations', function () {
    $user = makeUserWithRole(RoleAssociation::Comptable, $this->association);
    expect($user->can('create', Operation::class))->toBeFalse();
});

it('consultation cannot create operations', function () {
    $user = makeUserWithRole(RoleAssociation::Consultation, $this->association);
    expect($user->can('create', Operation::class))->toBeFalse();
});

it('all roles can view operations', function () {
    foreach (RoleAssociation::cases() as $role) {
        $user = makeUserWithRole($role, $this->association);
        expect($user->can('viewAny', Operation::class))->toBeTrue(
            "Role {$role->value} should be able to view operations"
        );
    }
});

// ── Transaction (Compta espace) ──

it('admin can create transactions', function () {
    $user = makeUserWithRole(RoleAssociation::Admin, $this->association);
    expect($user->can('create', Transaction::class))->toBeTrue();
});

it('comptable can create transactions', function () {
    $user = makeUserWithRole(RoleAssociation::Comptable, $this->association);
    expect($user->can('create', Transaction::class))->toBeTrue();
});

it('gestionnaire cannot create transactions', function () {
    $user = makeUserWithRole(RoleAssociation::Gestionnaire, $this->association);
    expect($user->can('create', Transaction::class))->toBeFalse();
});

it('consultation cannot create transactions', function () {
    $user = makeUserWithRole(RoleAssociation::Consultation, $this->association);
    expect($user->can('create', Transaction::class))->toBeFalse();
});

// ── Facture (Compta espace) ──

it('admin can create factures', function () {
    $user = makeUserWithRole(RoleAssociation::Admin, $this->association);
    expect($user->can('create', Facture::class))->toBeTrue();
});

it('comptable can create factures', function () {
    $user = makeUserWithRole(RoleAssociation::Comptable, $this->association);
    expect($user->can('create', Facture::class))->toBeTrue();
});

it('gestionnaire cannot create factures', function () {
    $user = makeUserWithRole(RoleAssociation::Gestionnaire, $this->association);
    expect($user->can('create', Facture::class))->toBeFalse();
});

// ── Tiers (both espaces) ──

it('admin can create tiers', function () {
    $user = makeUserWithRole(RoleAssociation::Admin, $this->association);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('comptable can create tiers', function () {
    $user = makeUserWithRole(RoleAssociation::Comptable, $this->association);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('gestionnaire can create tiers', function () {
    $user = makeUserWithRole(RoleAssociation::Gestionnaire, $this->association);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('consultation cannot create tiers', function () {
    $user = makeUserWithRole(RoleAssociation::Consultation, $this->association);
    expect($user->can('create', Tiers::class))->toBeFalse();
});

// ── User (Parametres / Admin only) ──

it('admin can manage users', function () {
    $user = makeUserWithRole(RoleAssociation::Admin, $this->association);
    expect($user->can('create', User::class))->toBeTrue();
    expect($user->can('viewAny', User::class))->toBeTrue();
});

it('non-admin cannot manage users', function () {
    foreach ([RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation] as $role) {
        $user = makeUserWithRole($role, $this->association);
        expect($user->can('create', User::class))->toBeFalse(
            "Role {$role->value} should not create users"
        );
    }
});

// ── User self-delete protection ──

it('admin cannot delete themselves', function () {
    $admin = makeUserWithRole(RoleAssociation::Admin, $this->association);
    expect($admin->can('delete', $admin))->toBeFalse();
});

it('admin can delete other users', function () {
    $admin = makeUserWithRole(RoleAssociation::Admin, $this->association);
    $other = makeUserWithRole(RoleAssociation::Comptable, $this->association);
    expect($admin->can('delete', $other))->toBeTrue();
});
