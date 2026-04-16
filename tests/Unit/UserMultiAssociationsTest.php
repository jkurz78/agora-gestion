<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

it('user has many associations via pivot', function () {
    $user = User::factory()->create();
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    $user->associations()->attach($assoA->id, ['role' => 'admin', 'joined_at' => now()]);
    $user->associations()->attach($assoB->id, ['role' => 'comptable', 'joined_at' => now()]);

    expect($user->associations)->toHaveCount(2);
});

it('currentAssociation reads from TenantContext', function () {
    $user = User::factory()->create();
    $asso = Association::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($asso);

    expect($user->currentAssociation())->not->toBeNull()
        ->and($user->currentAssociation()->id)->toBe($asso->id);
});

it('currentRole reads role from pivot for current tenant', function () {
    $user = User::factory()->create();
    $asso = Association::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($asso);

    expect($user->currentRole())->toBe('admin');
});

it('currentRole returns null when no tenant booted', function () {
    $user = User::factory()->create();

    expect($user->currentRole())->toBeNull();
});

it('isSuperAdmin returns true when role_systeme is super_admin', function () {
    $user = User::factory()->create(['role_systeme' => 'super_admin']);

    expect($user->isSuperAdmin())->toBeTrue();
});

it('isSuperAdmin returns false when role_systeme is user', function () {
    $user = User::factory()->create(['role_systeme' => 'user']);

    expect($user->isSuperAdmin())->toBeFalse();
});
