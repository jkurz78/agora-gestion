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

// écart() — même règle que couleurBarre(), mais rend un montant signé plutôt
// qu'une couleur : favorable = positif, dans les deux sens.

it('ecart charge : depenser moins que prevu est favorable (positif)', function () {
    expect(ComparaisonBudgetaire::ecart(1000.0, 800.0, true))->toBe(200.0);
});

it('ecart charge : depasser le prevu est defavorable (negatif)', function () {
    expect(ComparaisonBudgetaire::ecart(1000.0, 1200.0, true))->toBe(-200.0);
});

it('ecart produit : encaisser plus que prevu est favorable (positif)', function () {
    // Cas remonté en recette : dons prévu 600 / réalisé 670 → +70, jamais -70.
    expect(ComparaisonBudgetaire::ecart(600.0, 670.0, false))->toBe(70.0);
});

it('ecart produit : encaisser moins que prevu est defavorable (negatif)', function () {
    // Cas remonté en recette : cotisations prévu 500 / réalisé 300 → -200, jamais +200.
    expect(ComparaisonBudgetaire::ecart(500.0, 300.0, false))->toBe(-200.0);
});
