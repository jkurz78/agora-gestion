<?php

use App\Http\Middleware\BlockWritesInSupport;
use App\Http\Middleware\BootTenantConfig;
use App\Http\Middleware\EnforceDemoReadOnly;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\ForceWizardIfNotCompleted;
use App\Http\Middleware\Portail\BootTenantFromSlug;
use App\Http\Middleware\RedirectIfNotInstalled;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
            RedirectIfNotInstalled::class,
            ResolveTenant::class,
            BootTenantConfig::class,
            ForceWizardIfNotCompleted::class,
            BlockWritesInSupport::class,
        ]);

        // TenantContext doit être booté AVANT SubstituteBindings, sinon
        // le route-model binding sur un TenantModel (ex. Transaction) échoue
        // avec `WHERE 1 = 0` et renvoie un 404 avant d'atteindre le controller.
        //
        // RedirectIfAuthenticated (guest) doit être AVANT BootTenantFromSlug
        // afin qu'un user déjà authentifié visitant /{slug}/login soit redirigé
        // vers /dashboard SANS que BootTenantFromSlug ait eu le temps de basculer
        // le TenantContext vers l'asso du slug (protection contre le tenant-switch silencieux).
        $middleware->priority([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            RedirectIfNotInstalled::class,
            ResolveTenant::class,
            RedirectIfAuthenticated::class,
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

        // Rate limiter pour l'API newsletter publique : 5 requêtes / IP / heure.
        // Réponse 429 normalisée {"error": "rate_limit"} pour le contrat API.
        RateLimiter::for('newsletter', function (Request $request) {
            return Limit::perHour(
                (int) config('newsletter.rate_limit.max_attempts', 5)
            )
                ->by((string) $request->ip())
                ->response(fn () => response()->json(['error' => 'rate_limit'], 429));
        });

        $middleware->alias([
            'tenant.access' => EnsureTenantAccess::class,
            'boot-tenant' => BootTenantConfig::class,
            'super-admin' => EnsureSuperAdmin::class,
            'demo.read-only' => EnforceDemoReadOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Laravel 11's prepareException() wraps TokenMismatchException into
        // HttpException(419) *before* renderViaCallbacks runs, so a handler
        // typed against TokenMismatchException never fires. We hook on
        // HttpException and filter on the 419 status instead.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            // Idempotent logout: a stale CSRF token on the logout form would
            // otherwise leave the user silently logged in. Force the session
            // teardown so the UI state matches their mental model.
            if ($request->is('logout')) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/login')
                    ->with('status', 'Vous avez été déconnecté.');
            }

            return redirect('/')
                ->with('status', 'Votre session a expiré. Veuillez réessayer.');
        });
    })->create();
