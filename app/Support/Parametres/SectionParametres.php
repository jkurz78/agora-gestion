<?php

declare(strict_types=1);

namespace App\Support\Parametres;

use App\Enums\RoleAssociation;

final readonly class SectionParametres
{
    /** @param list<EcranParametre> $ecrans */
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $description,
        public string $icone,
        public array $ecrans,
    ) {}

    /** @return list<EcranParametre> */
    public function ecransVisibles(RoleAssociation $role): array
    {
        return array_values(array_filter(
            $this->ecrans,
            fn (EcranParametre $e): bool => $e->accessiblePar($role),
        ));
    }
}
