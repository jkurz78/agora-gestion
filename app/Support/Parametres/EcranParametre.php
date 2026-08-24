<?php

declare(strict_types=1);

namespace App\Support\Parametres;

use App\Enums\RoleAssociation;

/**
 * Un écran de la section Paramètres.
 *
 * La liste des rôles sert DEUX usages : le filtrage d'affichage (sidebar, page
 * d'accueil) et la garde serveur (CheckEspaceAccess). Une seule déclaration, donc
 * un écran ne peut pas être masqué mais accessible, ni listé mais interdit.
 */
final readonly class EcranParametre
{
    /** @param list<RoleAssociation> $roles */
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $route,
        public string $icone,
        public array $roles,
    ) {}

    public function accessiblePar(RoleAssociation $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
