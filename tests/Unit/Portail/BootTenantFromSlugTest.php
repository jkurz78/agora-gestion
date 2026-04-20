<?php

declare(strict_types=1);

use App\Http\Middleware\Portail\BootTenantFromSlug;
use App\Models\Association;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(fn () => TenantContext::clear());

it('boote le TenantContext avec l\'association du slug', function () {
    $asso = Association::factory()->create();

    $request = Request::create("/portail/{$asso->slug}/login", 'GET');
    // Bind route parameter manually
    $request->setRouteResolver(function () use ($asso) {
        $route = new Route('GET', '/portail/{association:slug}/login', []);
        $route->bind(Request::create("/portail/{$asso->slug}/login"));
        $route->setParameter('association', $asso);

        return $route;
    });

    $middleware = new BootTenantFromSlug;
    $middleware->handle($request, fn ($req) => new Response('ok'));

    expect(TenantContext::currentId())->toBe($asso->id);
});

it('passe silencieusement quand le paramètre association est absent (cas /livewire/update)', function () {
    $request = Request::create('/livewire/update', 'POST');
    $request->setRouteResolver(function () {
        $route = new Route('POST', '/livewire/update', []);
        $route->bind(Request::create('/livewire/update', 'POST'));

        // Pas de paramètre association — simule une requête Livewire
        return $route;
    });

    $middleware = new BootTenantFromSlug;
    $response = $middleware->handle($request, fn ($req) => new Response('ok'));

    expect($response->getContent())->toBe('ok');
    expect(TenantContext::hasBooted())->toBeFalse();
});

it('abort 404 si le slug ne correspond à aucune Association', function () {
    $request = Request::create('/portail/inexistant/login', 'GET');
    $request->setRouteResolver(function () {
        $route = new Route('GET', '/portail/{association:slug}/login', []);
        $route->bind(Request::create('/portail/inexistant/login'));
        $route->setParameter('association', 'inexistant');

        return $route;
    });

    $middleware = new BootTenantFromSlug;

    expect(fn () => $middleware->handle($request, fn ($req) => new Response('ok')))
        ->toThrow(HttpException::class);
});
