<?php

use App\Http\Middleware\BlockWritesInSupport;
use App\Http\Middleware\BootTenantConfig;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);

        $middleware->appendToGroup('web', [
            ResolveTenant::class,
            BootTenantConfig::class,
            BlockWritesInSupport::class,
        ]);

        $middleware->alias([
            'tenant.access' => EnsureTenantAccess::class,
            'boot-tenant' => BootTenantConfig::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            return redirect('/')
                ->with('status', 'Votre session a expiré. Veuillez réessayer.');
        });
    })->create();
