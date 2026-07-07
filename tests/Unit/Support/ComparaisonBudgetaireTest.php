<?php

declare(strict_types=1);

use App\Support\ComparaisonBudgetaire;

it('charge : vert sous le budget, orange en approche, rouge au dépassement', function () {
    expect(ComparaisonBudgetaire::couleurBarre(50.0, true))->toBe('#2E7D32');   // vert
    expect(ComparaisonBudgetaire::couleurBarre(95.0, true))->toBe('#fd7e14');   // orange
    expect(ComparaisonBudgetaire::couleurBarre(120.0, true))->toBe('#B5453A');  // rouge
});

it("produit : rouge sous l'objectif, orange en approche, vert à l'atteinte/dépassement", function () {
    expect(ComparaisonBudgetaire::couleurBarre(50.0, false))->toBe('#B5453A');  // rouge (moins que prévu)
    expect(ComparaisonBudgetaire::couleurBarre(95.0, false))->toBe('#fd7e14');  // orange (approche)
    expect(ComparaisonBudgetaire::couleurBarre(120.0, false))->toBe('#2E7D32'); // vert (plus que prévu)
});

it('produit pile à 100 % (objectif atteint) est vert', function () {
    expect(ComparaisonBudgetaire::couleurBarre(100.0, false))->toBe('#2E7D32');
});

it('charge pile à 100 % (budget consommé) est orange (à la limite)', function () {
    expect(ComparaisonBudgetaire::couleurBarre(100.0, true))->toBe('#fd7e14');
});
