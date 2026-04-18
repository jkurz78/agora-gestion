<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Models\User;
use App\Tenant\TenantContext;

it('denies unauthenticated access to /super-admin', function () {
    $this->get('/super-admin')->assertRedirect('/login');
});

it('denies a regular authenticated user', function () {
    $user = User::factory()->create(['role_systeme' => RoleSysteme::User]);
    $this->actingAs($user)->get('/super-admin')->assertForbidden();
});

it('grants access to a super-admin', function () {
    $user = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $this->actingAs($user)->get('/super-admin')->assertOk();
});

it('does not boot TenantContext on /super-admin routes', function () {
    // Clear any pre-booted context (global beforeEach boots a default association)
    // so we can assert the middleware itself doesn't boot a new one.
    TenantContext::clear();

    $user = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    // Même si l'user a une "current_association_id" en session, la page super-admin ne doit pas booter le tenant.
    session(['current_association_id' => 42]);
    $this->actingAs($user)->get('/super-admin');
    expect(TenantContext::currentId())->toBeNull();
});
