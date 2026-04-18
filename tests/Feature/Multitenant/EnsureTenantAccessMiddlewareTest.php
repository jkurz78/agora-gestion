<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTenantAccess;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

it('redirects to selector when TenantContext not booted', function () {
    TenantContext::clear();
    $user = User::factory()->create();
    $request = Request::create('/dashboard');
    auth()->login($user);

    $response = (new EnsureTenantAccess)->handle($request, fn () => response('ok'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toContain('association-selector');
});

it('allows request when user has access to current tenant', function () {
    $asso = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($asso);
    auth()->login($user);

    $response = (new EnsureTenantAccess)->handle(Request::create('/dashboard'), fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('403 when user does NOT have access to current tenant', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($assoA->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($assoB); // user n'a PAS accès
    auth()->login($user);

    expect(fn () => (new EnsureTenantAccess)->handle(Request::create('/dashboard'), fn () => response('ok')))
        ->toThrow(HttpException::class);
});
