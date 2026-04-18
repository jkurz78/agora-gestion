<?php

declare(strict_types=1);

use App\Http\Middleware\ResolveTenant;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;

beforeEach(fn () => TenantContext::clear());
afterEach(fn () => TenantContext::clear());

it('boots TenantContext from session current_association_id when user has access', function () {
    $asso = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    auth()->login($user);
    session(['current_association_id' => $asso->id]);

    $middleware = new ResolveTenant;
    $middleware->handle($request, function () {
        // dummy next
        return response('ok');
    });

    expect(TenantContext::currentId())->toBe($asso->id);
});

it('falls back to user derniere_association_id if session missing', function () {
    $asso = Association::factory()->create();
    $user = User::factory()->create(['derniere_association_id' => $asso->id]);
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));
    auth()->login($user);

    (new ResolveTenant)->handle($request, fn () => response('ok'));

    expect(TenantContext::currentId())->toBe($asso->id);
});

it('does not boot TenantContext when no user authenticated', function () {
    TenantContext::clear();
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    (new ResolveTenant)->handle($request, fn () => response('ok'));

    expect(TenantContext::hasBooted())->toBeFalse();
});
