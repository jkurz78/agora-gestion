<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Support\Parametres\EcranParametre;
use App\Support\Parametres\SectionParametres;

it('un écran connaît les rôles qui y accèdent', function (): void {
    $ecran = new EcranParametre(
        cle: 'plan-comptable',
        libelle: 'Plan comptable',
        route: 'parametres.plan-comptable',
        icone: 'bi-list-columns',
        roles: [RoleAssociation::Admin, RoleAssociation::Comptable],
    );

    expect($ecran->accessiblePar(RoleAssociation::Comptable))->toBeTrue();
    expect($ecran->accessiblePar(RoleAssociation::Gestionnaire))->toBeFalse();
    expect($ecran->accessiblePar(RoleAssociation::Consultation))->toBeFalse();
});

it('une section ne retient que les écrans visibles par le rôle', function (): void {
    $section = new SectionParametres(
        cle: 'comptabilite',
        libelle: 'Comptabilité',
        description: 'Comment les écritures sont ventilées et facturées.',
        icone: 'bi-calculator',
        ecrans: [
            new EcranParametre('a', 'A', 'parametres.a', 'bi-a', [RoleAssociation::Admin]),
            new EcranParametre('b', 'B', 'parametres.b', 'bi-b', [RoleAssociation::Admin, RoleAssociation::Comptable]),
        ],
    );

    expect($section->ecransVisibles(RoleAssociation::Admin))->toHaveCount(2);
    expect($section->ecransVisibles(RoleAssociation::Comptable))->toHaveCount(1);
    expect($section->ecransVisibles(RoleAssociation::Gestionnaire))->toHaveCount(0);
});
