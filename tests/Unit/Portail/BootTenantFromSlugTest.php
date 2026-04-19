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

it('abort 404 si le paramètre association n\'est pas un model Association', function () {
    $request = Request::create('/portail/inexistant/login', 'GET');
    $request->setRouteResolver(function () {
        $route = new Route('GET', '/portail/{association:slug}/login', []);
        $route->bind(Request::create('/portail/inexistant/login'));
        // Pas de paramètre association — simuler slug non résolu (null)
        $route->setParameter('association', null);

        return $route;
    });

    $middleware = new BootTenantFromSlug;

    expect(fn () => $middleware->handle($request, fn ($req) => new Response('ok')))
        ->toThrow(HttpException::class);
});
