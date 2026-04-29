<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the application against the absence of a super-admin user.
 *
 * - When NO super-admin exists (fresh install) :
 *   - allow `/setup` and infrastructure paths (assets, livewire, healthcheck)
 *   - redirect every other URL to `/setup`
 *
 * - When a super-admin exists :
 *   - redirect `/setup` to `/login`
 *   - let everything else pass through
 */
final class RedirectIfNotInstalled
{
    /**
     * @var list<string>
     */
    private const EXEMPT_PATHS = [
        'setup',
        'livewire/*',
        'livewire-*',
        'build/*',
        'storage/*',
        'tenant-assets/*',
        'up',
        '_ignition/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $installed = User::superAdminExists();

        if (! $installed) {
            foreach (self::EXEMPT_PATHS as $pattern) {
                if ($request->is($pattern)) {
                    return $next($request);
                }
            }

            return redirect('/setup');
        }

        if ($request->is('setup')) {
            return redirect('/login');
        }

        return $next($request);
    }
}
