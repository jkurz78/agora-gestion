<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Enums\RoleAssociation;

it('has four cases', function () {
    expect(RoleAssociation::cases())->toHaveCount(4);
});

it('admin can read and write both espaces', function () {
    expect(RoleAssociation::Admin->canRead(Espace::Compta))->toBeTrue();
    expect(RoleAssociation::Admin->canWrite(Espace::Compta))->toBeTrue();
    expect(RoleAssociation::Admin->canRead(Espace::Gestion))->toBeTrue();
    expect(RoleAssociation::Admin->canWrite(Espace::Gestion))->toBeTrue();
    expect(RoleAssociation::Admin->canAccessParametres())->toBeTrue();
});

it('comptable can read+write compta, read-only gestion, no parametres', function () {
    expect(RoleAssociation::Comptable->canRead(Espace::Compta))->toBeTrue();
    expect(RoleAssociation::Comptable->canWrite(Espace::Compta))->toBeTrue();
    expect(RoleAssociation::Comptable->canRead(Espace::Gestion))->toBeTrue();
    expect(RoleAssociation::Comptable->canWrite(Espace::Gestion))->toBeFalse();
    expect(RoleAssociation::Comptable->canAccessParametres())->toBeFalse();
});

it('gestionnaire can read+write gestion, read-only compta, no parametres', function () {
    expect(RoleAssociation::Gestionnaire->canRead(Espace::Compta))->toBeTrue();
    expect(RoleAssociation::Gestionnaire->canWrite(Espace::Compta))->toBeFalse();
    expect(RoleAssociation::Gestionnaire->canRead(Espace::Gestion))->toBeTrue();
    expect(RoleAssociation::Gestionnaire->canWrite(Espace::Gestion))->toBeTrue();
    expect(RoleAssociation::Gestionnaire->canAccessParametres())->toBeFalse();
});

it('consultation can only read both espaces, no write, no parametres', function () {
    expect(RoleAssociation::Consultation->canRead(Espace::Compta))->toBeTrue();
    expect(RoleAssociation::Consultation->canWrite(Espace::Compta))->toBeFalse();
    expect(RoleAssociation::Consultation->canRead(Espace::Gestion))->toBeTrue();
    expect(RoleAssociation::Consultation->canWrite(Espace::Gestion))->toBeFalse();
    expect(RoleAssociation::Consultation->canAccessParametres())->toBeFalse();
});

it('provides a French label for each role', function () {
    expect(RoleAssociation::Admin->label())->toBe('Administrateur');
    expect(RoleAssociation::Comptable->label())->toBe('Comptable');
    expect(RoleAssociation::Gestionnaire->label())->toBe('Gestionnaire');
    expect(RoleAssociation::Consultation->label())->toBe('Consultation');
});
