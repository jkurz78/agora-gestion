<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RoleAssociation;
use App\Support\Parametres\ParametresNavigation;
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
            if ($role === null || ! ParametresNavigation::auMoinsUnEcran($role)) {
                abort(403, 'Accès réservé aux administrateurs.');
            }

            // Droit écran par écran : la taxonomie est la seule source. Une route
            // de Paramètres absente de l'arbre reste réservée aux administrateurs
            // — un écran non déclaré ne s'ouvre pas par défaut. Seule exception :
            // la page d'accueil (parametres.index) n'est pas un écran de l'arbre,
            // c'est le hub qui les liste tous — elle suit la même condition que
            // le contrôle ci-dessus (auMoinsUnEcran) plutôt que le repli
            // Admin-only, sans quoi un Gestionnaire ou un Comptable verrait ses
            // propres écrans dans la sidebar mais recevrait un 403 en cliquant
            // sur « Paramètres ».
            $routeName = (string) $request->route()?->getName();
            $position = ParametresNavigation::localiser($routeName);

            if ($position === null) {
                if ($routeName !== 'parametres.index' && $role !== RoleAssociation::Admin) {
                    abort(403, 'Accès réservé aux administrateurs.');
                }
            } elseif (! $position['ecran']->accessiblePar($role)) {
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
