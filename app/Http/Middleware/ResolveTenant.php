<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Association;
use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // Super-admin zone : on ne boote jamais de tenant ici.
        if ($request->is('super-admin', 'super-admin/*')) {
            return $next($request);
        }

        $user = auth()->user();

        if ($user === null) {
            return $next($request);
        }

        // Support mode : boote le tenant sans vérification de pivot (le super-admin n'est pas dans association_user).
        if ($request->session()->get('support_mode', false) && $user->isSuperAdmin()) {
            $supportAssoId = (int) $request->session()->get('support_association_id', 0);
            if ($supportAssoId > 0) {
                $association = Association::find($supportAssoId);
                if ($association !== null) {
                    TenantContext::boot($association);

                    return $next($request);
                }
            }
        }

        $assoId = $request->session()->get('current_association_id')
            ?? $user->derniere_association_id;

        if ($assoId === null) {
            return $next($request);
        }

        // Vérifier que l'user a bien accès à cette asso (sécurité)
        $hasAccess = $user->associations()
            ->wherePivot('association_id', $assoId)
            ->whereNull('association_user.revoked_at')
            ->exists();

        if (! $hasAccess) {
            $request->session()->forget('current_association_id');

            return $next($request);
        }

        $association = Association::find($assoId);
        if ($association !== null) {
            TenantContext::boot($association);
            $request->session()->put('current_association_id', $association->id);
        }

        return $next($request);
    }
}
