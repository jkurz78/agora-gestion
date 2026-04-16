<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RoleAssociation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CheckEspaceAccess
{
    public function handle(Request $request, Closure $next, string $level = 'read'): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $role = RoleAssociation::tryFrom($user->currentRole() ?? '');

        if ($level === 'parametres') {
            if (! ($role?->canAccessParametres() ?? false)) {
                abort(403, 'Accès réservé aux administrateurs.');
            }

            return $next($request);
        }

        $espace = $request->attributes->get('espace');

        if ($espace && ! ($role?->canRead($espace) ?? false)) {
            abort(403, 'Vous n\'avez pas accès à cet espace.');
        }

        return $next($request);
    }
}
