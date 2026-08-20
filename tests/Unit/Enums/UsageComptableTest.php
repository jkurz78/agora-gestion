<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;

it('has six cases', function () {
    expect(UsageComptable::cases())->toHaveCount(6);
});

it('returns label fr for each case', function () {
    expect(UsageComptable::Don->label())->toBe('Dons');
    expect(UsageComptable::Cotisation->label())->toBe('Cotisations');
    expect(UsageComptable::Inscription->label())->toBe('Inscriptions');
    expect(UsageComptable::FraisKilometriques->label())->toBe('Indemnités kilométriques');
    expect(UsageComptable::AbandonCreance->label())->toBe('Abandon de créance');
    expect(UsageComptable::Gratuite->label())->toBe('Gratuités accordées');
});

it('returns polarity (categorie type)', function () {
    expect(UsageComptable::FraisKilometriques->polarite())->toBe(TypeCategorie::Depense);
    expect(UsageComptable::Don->polarite())->toBe(TypeCategorie::Recette);
    expect(UsageComptable::Cotisation->polarite())->toBe(TypeCategorie::Recette);
    expect(UsageComptable::Inscription->polarite())->toBe(TypeCategorie::Recette);
    expect(UsageComptable::AbandonCreance->polarite())->toBe(TypeCategorie::Recette);
    expect(UsageComptable::Gratuite->polarite())->toBe(TypeCategorie::Recette);
});

it('returns cardinality', function () {
    expect(UsageComptable::FraisKilometriques->cardinalite())->toBe('mono');
    expect(UsageComptable::AbandonCreance->cardinalite())->toBe('mono');
    expect(UsageComptable::Gratuite->cardinalite())->toBe('mono');
    expect(UsageComptable::Don->cardinalite())->toBe('multi');
    expect(UsageComptable::Cotisation->cardinalite())->toBe('multi');
    expect(UsageComptable::Inscription->cardinalite())->toBe('multi');
});
