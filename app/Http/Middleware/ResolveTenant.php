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
        $user = auth()->user();

        if ($user === null) {
            return $next($request);
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
