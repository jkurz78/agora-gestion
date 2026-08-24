<?php

declare(strict_types=1);

namespace App\Support\Parametres;

use App\Enums\RoleAssociation;
use InvalidArgumentException;

final readonly class SectionParametres
{
    /** @param list<EcranParametre> $ecrans */
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $description,
        public string $icone,
        public array $ecrans,
    ) {
        foreach ($this->ecrans as $ecran) {
            if (! $ecran instanceof EcranParametre) {
                throw new InvalidArgumentException(sprintf(
                    'La section « %s » déclare un écran invalide : une instance de %s est attendue, %s reçu.',
                    $this->cle,
                    EcranParametre::class,
                    self::decrireValeur($ecran),
                ));
            }
        }
    }

    private static function decrireValeur(mixed $valeur): string
    {
        return is_scalar($valeur) ? var_export($valeur, true) : get_debug_type($valeur);
    }

    /** @return list<EcranParametre> */
    public function ecransVisibles(RoleAssociation $role): array
    {
        return array_values(array_filter(
            $this->ecrans,
            fn (EcranParametre $e): bool => $e->accessiblePar($role),
        ));
    }
}
