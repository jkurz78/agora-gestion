<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! TenantContext::hasBooted()) {
            return redirect()->route('association-selector');
        }

        $assoId = TenantContext::currentId();
        $hasAccess = $user->associations()
            ->wherePivot('association_id', $assoId)
            ->whereNull('association_user.revoked_at')
            ->exists();

        if (! $hasAccess) {
            abort(403, 'Accès refusé à cette association.');
        }

        return $next($request);
    }
}
