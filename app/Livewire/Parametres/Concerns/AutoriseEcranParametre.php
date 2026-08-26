<?php

declare(strict_types=1);

namespace App\Livewire\Parametres\Concerns;

use App\Enums\RoleAssociation;
use App\Support\Parametres\ParametresNavigation;
use Illuminate\Support\Facades\Auth;

/**
 * Autorise un composant d'écran de Paramètres à CHAQUE requête Livewire.
 *
 * Le middleware ne garde que le GET initial : la liste des middlewares
 * persistants de Livewire ne contient que de l'authentification, jamais
 * d'autorisation. Sans ce garde, un composant monté accepte indéfiniment des
 * appels de méthode — même après rétrogradation du rôle en cours de session.
 *
 * `booted()` est le bon point d'accroche : Livewire l'exécute à chaque requête,
 * hydratation comprise, là où `mount()` ne passe qu'une fois.
 */
trait AutoriseEcranParametre
{
    /** Clé de l'écran dans ParametresNavigation. */
    abstract protected function cleEcranParametre(): string;

    public function booted(): void
    {
        $role = RoleAssociation::tryFrom(Auth::user()?->currentRole() ?? '');

        if ($role === null) {
            abort(403, 'Accès réservé aux administrateurs.');
        }

        foreach (ParametresNavigation::sections() as $section) {
            foreach ($section->ecrans as $ecran) {
                if ($ecran->cle === $this->cleEcranParametre()) {
                    abort_unless($ecran->accessiblePar($role), 403, 'Accès réservé aux administrateurs.');

                    return;
                }
            }
        }

        // Écran non déclaré en taxonomie : refus par défaut, jamais l'inverse.
        abort(403, 'Accès réservé aux administrateurs.');
    }
}
