<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ForceWizardIfNotCompleted
{
    /**
     * @var list<string>
     */
    private const EXEMPT_PATHS = [
        'onboarding',
        'onboarding/*',
        'super-admin',
        'super-admin/*',
        'livewire/*',
        'logout',
        'login',
        'password/*',
        'reset-password/*',
        'forgot-password',
        'verify-email',
        'verify-email/*',
        'email/verification-notification',
        'build/*',
        'storage/*',
        'tenant-assets/*',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->isSuperAdmin()) {
            return $next($request);
        }

        foreach (self::EXEMPT_PATHS as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        $association = TenantContext::current();
        if ($association === null) {
            return $next($request);
        }

        if ($association->wizard_completed_at !== null) {
            return $next($request);
        }

        $isAdmin = $user->associations()
            ->wherePivot('association_id', $association->id)
            ->wherePivot('role', 'admin')
            ->exists();

        if (! $isAdmin) {
            return $next($request);
        }

        return redirect()->route('onboarding.index');
    }
}
