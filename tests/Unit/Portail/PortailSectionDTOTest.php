<?php

declare(strict_types=1);

use App\Support\Portail\PortailSectionDTO;

it('se construit avec toutes les propriétés obligatoires et optionnelles', function (): void {
    $dto = new PortailSectionDTO(
        id: 'tableau-de-bord',
        label: 'Tableau de bord',
        routeName: 'portail.home',
        icon: 'bi-house-door',
        ordre: 1,
        groupe: 'Espace personnel',
        visible: true,
        badge: null,
    );

    expect($dto->id)->toBe('tableau-de-bord')
        ->and($dto->label)->toBe('Tableau de bord')
        ->and($dto->routeName)->toBe('portail.home')
        ->and($dto->icon)->toBe('bi-house-door')
        ->and($dto->ordre)->toBe(1)
        ->and($dto->groupe)->toBe('Espace personnel')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});

it('accepte groupe nul', function (): void {
    $dto = new PortailSectionDTO(
        id: 'mon-profil',
        label: 'Mon profil',
        routeName: 'portail.profil',
        icon: 'bi-person',
        ordre: 2,
        groupe: null,
        visible: true,
        badge: null,
    );

    expect($dto->groupe)->toBeNull();
});

it('accepte un badge non nul', function (): void {
    $dto = new PortailSectionDTO(
        id: 'notes-de-frais',
        label: 'Notes de frais',
        routeName: 'portail.ndf.index',
        icon: 'bi-receipt',
        ordre: 10,
        groupe: 'Mes frais & factures',
        visible: true,
        badge: 3,
    );

    expect($dto->badge)->toBe(3);
});

it('est immuable — les propriétés readonly ne peuvent pas être réassignées', function (): void {
    $dto = new PortailSectionDTO(
        id: 'tableau-de-bord',
        label: 'Tableau de bord',
        routeName: 'portail.home',
        icon: 'bi-house-door',
        ordre: 1,
        groupe: null,
        visible: true,
        badge: null,
    );

    expect(fn () => $dto->id = 'autre')->toThrow(Error::class);
});

it('deux DTOs identiques ont les mêmes valeurs de propriétés', function (): void {
    $a = new PortailSectionDTO(
        id: 'mon-profil',
        label: 'Mon profil',
        routeName: 'portail.profil',
        icon: 'bi-person',
        ordre: 2,
        groupe: 'Espace personnel',
        visible: true,
        badge: null,
    );

    $b = new PortailSectionDTO(
        id: 'mon-profil',
        label: 'Mon profil',
        routeName: 'portail.profil',
        icon: 'bi-person',
        ordre: 2,
        groupe: 'Espace personnel',
        visible: true,
        badge: null,
    );

    expect($a->id)->toBe($b->id)
        ->and($a->label)->toBe($b->label)
        ->and($a->routeName)->toBe($b->routeName)
        ->and($a->icon)->toBe($b->icon)
        ->and($a->ordre)->toBe($b->ordre)
        ->and($a->groupe)->toBe($b->groupe)
        ->and($a->visible)->toBe($b->visible)
        ->and($a->badge)->toBe($b->badge);
});

it('visible vaut true par défaut', function (): void {
    $dto = new PortailSectionDTO(
        id: 'tableau-de-bord',
        label: 'Tableau de bord',
        routeName: 'portail.home',
        icon: 'bi-house-door',
        ordre: 1,
        groupe: null,
        visible: true,
        badge: null,
    );

    expect($dto->visible)->toBeTrue();
});

it('accepte visible à false pour usage futur', function (): void {
    $dto = new PortailSectionDTO(
        id: 'section-cachee',
        label: 'Section cachée',
        routeName: 'portail.cachee',
        icon: 'bi-eye-slash',
        ordre: 99,
        groupe: null,
        visible: false,
        badge: null,
    );

    expect($dto->visible)->toBeFalse();
});
