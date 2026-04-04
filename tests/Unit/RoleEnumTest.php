<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Enums\Role;

it('has four cases', function () {
    expect(Role::cases())->toHaveCount(4);
});

it('admin can read and write both espaces', function () {
    expect(Role::Admin->canRead(Espace::Compta))->toBeTrue();
    expect(Role::Admin->canWrite(Espace::Compta))->toBeTrue();
    expect(Role::Admin->canRead(Espace::Gestion))->toBeTrue();
    expect(Role::Admin->canWrite(Espace::Gestion))->toBeTrue();
    expect(Role::Admin->canAccessParametres())->toBeTrue();
});

it('comptable can read+write compta, read-only gestion, no parametres', function () {
    expect(Role::Comptable->canRead(Espace::Compta))->toBeTrue();
    expect(Role::Comptable->canWrite(Espace::Compta))->toBeTrue();
    expect(Role::Comptable->canRead(Espace::Gestion))->toBeTrue();
    expect(Role::Comptable->canWrite(Espace::Gestion))->toBeFalse();
    expect(Role::Comptable->canAccessParametres())->toBeFalse();
});

it('gestionnaire can read+write gestion, read-only compta, no parametres', function () {
    expect(Role::Gestionnaire->canRead(Espace::Compta))->toBeTrue();
    expect(Role::Gestionnaire->canWrite(Espace::Compta))->toBeFalse();
    expect(Role::Gestionnaire->canRead(Espace::Gestion))->toBeTrue();
    expect(Role::Gestionnaire->canWrite(Espace::Gestion))->toBeTrue();
    expect(Role::Gestionnaire->canAccessParametres())->toBeFalse();
});

it('consultation can only read both espaces, no write, no parametres', function () {
    expect(Role::Consultation->canRead(Espace::Compta))->toBeTrue();
    expect(Role::Consultation->canWrite(Espace::Compta))->toBeFalse();
    expect(Role::Consultation->canRead(Espace::Gestion))->toBeTrue();
    expect(Role::Consultation->canWrite(Espace::Gestion))->toBeFalse();
    expect(Role::Consultation->canAccessParametres())->toBeFalse();
});

it('provides a French label for each role', function () {
    expect(Role::Admin->label())->toBe('Administrateur');
    expect(Role::Comptable->label())->toBe('Comptable');
    expect(Role::Gestionnaire->label())->toBe('Gestionnaire');
    expect(Role::Consultation->label())->toBe('Consultation');
});
