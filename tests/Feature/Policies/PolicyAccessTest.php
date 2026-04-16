<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Operation (Gestion espace) ──

it('admin can create operations', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);
    expect($user->can('create', Operation::class))->toBeTrue();
});

it('gestionnaire can create operations', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);
    expect($user->can('create', Operation::class))->toBeTrue();
});

it('comptable cannot create operations', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);
    expect($user->can('create', Operation::class))->toBeFalse();
});

it('consultation cannot create operations', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);
    expect($user->can('create', Operation::class))->toBeFalse();
});

it('all roles can view operations', function () {
    foreach (RoleAssociation::cases() as $role) {
        $user = User::factory()->create(['role' => $role]);
        expect($user->can('viewAny', Operation::class))->toBeTrue(
            "Role {$role->value} should be able to view operations"
        );
    }
});

// ── Transaction (Compta espace) ──

it('admin can create transactions', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);
    expect($user->can('create', Transaction::class))->toBeTrue();
});

it('comptable can create transactions', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);
    expect($user->can('create', Transaction::class))->toBeTrue();
});

it('gestionnaire cannot create transactions', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);
    expect($user->can('create', Transaction::class))->toBeFalse();
});

it('consultation cannot create transactions', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);
    expect($user->can('create', Transaction::class))->toBeFalse();
});

// ── Facture (Compta espace) ──

it('admin can create factures', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);
    expect($user->can('create', Facture::class))->toBeTrue();
});

it('comptable can create factures', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);
    expect($user->can('create', Facture::class))->toBeTrue();
});

it('gestionnaire cannot create factures', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);
    expect($user->can('create', Facture::class))->toBeFalse();
});

// ── Tiers (both espaces) ──

it('admin can create tiers', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('comptable can create tiers', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('gestionnaire can create tiers', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);
    expect($user->can('create', Tiers::class))->toBeTrue();
});

it('consultation cannot create tiers', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);
    expect($user->can('create', Tiers::class))->toBeFalse();
});

// ── User (Parametres / Admin only) ──

it('admin can manage users', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);
    expect($user->can('create', User::class))->toBeTrue();
    expect($user->can('viewAny', User::class))->toBeTrue();
});

it('non-admin cannot manage users', function () {
    foreach ([RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation] as $role) {
        $user = User::factory()->create(['role' => $role]);
        expect($user->can('create', User::class))->toBeFalse(
            "Role {$role->value} should not create users"
        );
    }
});

// ── User self-delete protection ──

it('admin cannot delete themselves', function () {
    $admin = User::factory()->create(['role' => RoleAssociation::Admin]);
    expect($admin->can('delete', $admin))->toBeFalse();
});

it('admin can delete other users', function () {
    $admin = User::factory()->create(['role' => RoleAssociation::Admin]);
    $other = User::factory()->create(['role' => RoleAssociation::Comptable]);
    expect($admin->can('delete', $other))->toBeTrue();
});
