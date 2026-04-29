<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('returns false when no super-admin exists', function () {
    Cache::forget('app.installed');

    expect(User::superAdminExists())->toBeFalse();
});

it('returns true when at least one super-admin exists', function () {
    User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);

    Cache::forget('app.installed');

    expect(User::superAdminExists())->toBeTrue();
});

it('caches the positive result', function () {
    User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    Cache::forget('app.installed');

    User::superAdminExists();

    expect(Cache::has('app.installed'))->toBeTrue();
});

it('invalidates the cache when a user is promoted to super-admin', function () {
    Cache::put('app.installed', false, 3600);

    $user = User::factory()->create(['role_systeme' => RoleSysteme::User]);
    $user->update(['role_systeme' => RoleSysteme::SuperAdmin]);

    expect(Cache::has('app.installed'))->toBeFalse();
});

it('invalidates the cache when a super-admin is demoted', function () {
    $user = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    Cache::put('app.installed', true, 3600);

    $user->update(['role_systeme' => RoleSysteme::User]);

    expect(Cache::has('app.installed'))->toBeFalse();
});
