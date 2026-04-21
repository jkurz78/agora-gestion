<?php

use App\Http\Middleware\BlockWritesInSupport;
use App\Http\Middleware\BootTenantConfig;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\ForceWizardIfNotCompleted;
use App\Http\Middleware\Portail\BootTenantFromSlug;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/portail.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);

        $middleware->appendToGroup('web', [
            ResolveTenant::class,
            BootTenantConfig::class,
            ForceWizardIfNotCompleted::class,
            BlockWritesInSupport::class,
        ]);

        // TenantContext doit être booté AVANT SubstituteBindings, sinon
        // le route-model binding sur un TenantModel (ex. Transaction) échoue
        // avec `WHERE 1 = 0` et renvoie un 404 avant d'atteindre le controller.
        $middleware->priority([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            ResolveTenant::class,
            BootTenantFromSlug::class,
            BootTenantConfig::class,
            Authenticate::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            SubstituteBindings::class,
            AuthenticatesRequests::class,
            Authorize::class,
            ForceWizardIfNotCompleted::class,
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
