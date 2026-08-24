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

it('un écran sans rôle déclaré n\'est accessible à personne', function (): void {
    $ecran = new EcranParametre(
        cle: 'aucun-acces',
        libelle: 'Aucun accès',
        route: 'parametres.aucun-acces',
        icone: 'bi-x',
        roles: [],
    );

    expect($ecran->accessiblePar(RoleAssociation::Admin))->toBeFalse();
    expect($ecran->accessiblePar(RoleAssociation::Comptable))->toBeFalse();
    expect($ecran->accessiblePar(RoleAssociation::Gestionnaire))->toBeFalse();
    expect($ecran->accessiblePar(RoleAssociation::Consultation))->toBeFalse();
});

it('un écran rejette un rôle qui n\'est pas une instance de RoleAssociation', function (): void {
    expect(fn () => new EcranParametre(
        cle: 'plan-comptable',
        libelle: 'Plan comptable',
        route: 'parametres.plan-comptable',
        icone: 'bi-list-columns',
        roles: ['admin'],
    ))->toThrow(InvalidArgumentException::class, 'plan-comptable');
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

    $cles = fn (array $ecrans): array => array_map(fn (EcranParametre $e): string => $e->cle, $ecrans);

    expect($cles($section->ecransVisibles(RoleAssociation::Admin)))->toBe(['a', 'b']);
    expect($cles($section->ecransVisibles(RoleAssociation::Comptable)))->toBe(['b']);
    expect($cles($section->ecransVisibles(RoleAssociation::Gestionnaire)))->toBe([]);
});

it('une section rejette un écran qui n\'est pas une instance de EcranParametre', function (): void {
    expect(fn () => new SectionParametres(
        cle: 'comptabilite',
        libelle: 'Comptabilité',
        description: 'Comment les écritures sont ventilées et facturées.',
        icone: 'bi-calculator',
        ecrans: ['pas-un-ecran'],
    ))->toThrow(InvalidArgumentException::class, 'comptabilite');
});
