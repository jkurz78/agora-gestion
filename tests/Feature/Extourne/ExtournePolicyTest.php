<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\RoleSysteme;
use App\Models\Extourne;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;

function createUserWithRole(RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => TenantContext::currentId()]);

    return $user;
}

test('Admin can create extourne', function (): void {
    $user = createUserWithRole(RoleAssociation::Admin);
    $tx = Transaction::factory()->create();

    expect($user->can('create', [Extourne::class, $tx]))->toBeTrue();
});

test('Comptable can create extourne', function (): void {
    $user = createUserWithRole(RoleAssociation::Comptable);
    $tx = Transaction::factory()->create();

    expect($user->can('create', [Extourne::class, $tx]))->toBeTrue();
});

test('Gestionnaire cannot create extourne', function (): void {
    $user = createUserWithRole(RoleAssociation::Gestionnaire);
    $tx = Transaction::factory()->create();

    expect($user->can('create', [Extourne::class, $tx]))->toBeFalse();
});

test('Consultation cannot create extourne', function (): void {
    $user = createUserWithRole(RoleAssociation::Consultation);
    $tx = Transaction::factory()->create();

    expect($user->can('create', [Extourne::class, $tx]))->toBeFalse();
});

test('User without role in current tenant cannot create extourne', function (): void {
    $user = User::factory()->create();
    $tx = Transaction::factory()->create();

    expect($user->can('create', [Extourne::class, $tx]))->toBeFalse();
});

test('Super-admin not attached to tenant cannot create extourne (read-only support mode)', function (): void {
    $user = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $tx = Transaction::factory()->create();

    expect($user->can('create', [Extourne::class, $tx]))->toBeFalse();
});
