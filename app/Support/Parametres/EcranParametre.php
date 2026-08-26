<?php

declare(strict_types=1);

namespace App\Support\Parametres;

use App\Enums\RoleAssociation;
use InvalidArgumentException;

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
    ) {
        foreach ($this->roles as $role) {
            if (! $role instanceof RoleAssociation) {
                throw new InvalidArgumentException(sprintf(
                    'L\'écran « %s » déclare un rôle invalide : une instance de %s est attendue, %s reçu.',
                    $this->cle,
                    RoleAssociation::class,
                    self::decrireValeur($role),
                ));
            }
        }
    }

    private static function decrireValeur(mixed $valeur): string
    {
        return is_scalar($valeur) ? var_export($valeur, true) : get_debug_type($valeur);
    }

    public function accessiblePar(RoleAssociation $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
