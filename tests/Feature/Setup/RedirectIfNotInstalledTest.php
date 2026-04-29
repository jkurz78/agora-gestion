<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget('app.installed');
});

it('redirects any non-exempt URL to /setup when no super-admin exists', function () {
    $this->get('/login')->assertRedirect('/setup');
    $this->get('/dashboard')->assertRedirect('/setup');
    $this->get('/')->assertRedirect('/setup');
});

it('lets /setup through when no super-admin exists', function () {
    $this->get('/setup')->assertOk();
});

it('lets exempt asset paths through even when no super-admin exists', function () {
    // /up est le healthcheck Laravel — toujours accessible
    $this->get('/up')->assertOk();
});

it('redirects /setup to /login when a super-admin already exists', function () {
    User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    Cache::forget('app.installed');

    $this->get('/setup')->assertRedirect('/login');
});

it('lets normal URLs through when a super-admin exists', function () {
    User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    Cache::forget('app.installed');

    // /login devrait s'afficher normalement
    $this->get('/login')->assertOk();
});
