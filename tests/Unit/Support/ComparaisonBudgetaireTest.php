<?php

declare(strict_types=1);

use App\Support\ComparaisonBudgetaire;

// couleurBarre() — refonte 2026-09-01 : six paliers asymétriques, tolérance
// de 3 % autour de la cible. Le côté FAVORABLE est toujours vert (aucune
// dégradation), seule la mauvaise direction s'assombrit progressivement.
// Remplace l'ancien barème à 3 paliers (vert/orange/rouge, vert inatteignable
// pour une charge en dessous de 90 % seulement) qui rendait le vert quasi
// impossible à obtenir pour un budget correctement tenu.

it('charge : cote favorable toujours vert, y compris tres en dessous du budget', function () {
    expect(ComparaisonBudgetaire::couleurBarre(40.0, true))->toBe('#2E7D32');
    // Cas qui motivait la refonte : une charge à 97 % était orange, elle doit être verte.
    expect(ComparaisonBudgetaire::couleurBarre(97.0, true))->toBe('#2E7D32');
});

it('charge : bornes exactes des six paliers', function () {
    expect(ComparaisonBudgetaire::couleurBarre(103.0, true))->toBe('#2E7D32');   // vert, borne haute incluse
    expect(ComparaisonBudgetaire::couleurBarre(103.1, true))->toBe('#E3B341');   // jaune
    expect(ComparaisonBudgetaire::couleurBarre(108.0, true))->toBe('#E3B341');   // jaune
    expect(ComparaisonBudgetaire::couleurBarre(108.1, true))->toBe('#E07B39');   // orange
    expect(ComparaisonBudgetaire::couleurBarre(113.0, true))->toBe('#E07B39');   // orange
    expect(ComparaisonBudgetaire::couleurBarre(113.1, true))->toBe('#C85A2A');   // orange foncé
    expect(ComparaisonBudgetaire::couleurBarre(118.0, true))->toBe('#C85A2A');   // orange foncé
    expect(ComparaisonBudgetaire::couleurBarre(118.1, true))->toBe('#A83C32');   // rouge
    expect(ComparaisonBudgetaire::couleurBarre(123.0, true))->toBe('#A83C32');   // rouge
    expect(ComparaisonBudgetaire::couleurBarre(123.1, true))->toBe('#6E1E18');   // rouge foncé
});

it('produit : cote favorable toujours vert, y compris tres au dessus du budget', function () {
    expect(ComparaisonBudgetaire::couleurBarre(150.0, false))->toBe('#2E7D32');
    // Cas qui motivait la refonte : un produit à 99 % était orange, il doit être vert.
    expect(ComparaisonBudgetaire::couleurBarre(99.0, false))->toBe('#2E7D32');
});

it('produit : bornes exactes des six paliers (symétrique inverse de la charge)', function () {
    expect(ComparaisonBudgetaire::couleurBarre(97.0, false))->toBe('#2E7D32');   // vert, borne basse incluse
    expect(ComparaisonBudgetaire::couleurBarre(96.9, false))->toBe('#E3B341');   // jaune
    expect(ComparaisonBudgetaire::couleurBarre(92.0, false))->toBe('#E3B341');   // jaune
    expect(ComparaisonBudgetaire::couleurBarre(91.9, false))->toBe('#E07B39');   // orange
    expect(ComparaisonBudgetaire::couleurBarre(87.0, false))->toBe('#E07B39');   // orange
    expect(ComparaisonBudgetaire::couleurBarre(86.9, false))->toBe('#C85A2A');   // orange foncé
    expect(ComparaisonBudgetaire::couleurBarre(82.0, false))->toBe('#C85A2A');   // orange foncé
    expect(ComparaisonBudgetaire::couleurBarre(81.9, false))->toBe('#A83C32');   // rouge
    expect(ComparaisonBudgetaire::couleurBarre(77.0, false))->toBe('#A83C32');   // rouge
    expect(ComparaisonBudgetaire::couleurBarre(76.9, false))->toBe('#6E1E18');   // rouge foncé
});

it('produit : un realise negatif (contra-produit debite) donne un pct negatif, rouge fonce', function () {
    // Ex. compte 709A Gratuités accordées, débité : montant_n négatif pour un
    // budget positif → pct négatif, tout en bas de la rampe. Pas de division
    // par zéro : c'est $budget qui est au dénominateur, jamais $montantN, et
    // $renderBar() garde déjà $budget <= 0 en amont.
    expect(ComparaisonBudgetaire::couleurBarre(-40.0, false))->toBe('#6E1E18');
});

it('charge : un pct negatif ne tombe PAS en bas de la rampe, contrairement au produit', function () {
    // Asymétrie documentée sur couleurBarre() : côté charge, -40 % passe le
    // premier palier `<= 103` et ressort VERTE (un compte de charge négatif
    // est un remboursement ou un avoir reçu, une bonne nouvelle), alors que
    // le même -40 % côté produit tombe tout en bas de la rampe (test
    // ci-dessus). Une ancienne version de la docblock affirmait à tort que
    // la ligne « tombe tout en bas de la rampe » dans les deux cas.
    expect(ComparaisonBudgetaire::couleurBarre(-40.0, true))->toBe('#2E7D32');
});

// Réalisé nul : au 1er octobre, toutes les recettes budgétées sont à 0 %
// encaissé et sortaient en rouge foncé — le palier le plus bas de la rampe.
// L'écran entier était maximalement alarmant, donc plus rien n'alertait. La
// barre dit l'avancement, le nombre porte le jugement : réalisé nul, barre
// vide ; l'écart chiffré, lui, garde sa couleur.

it('un realise nul ne rend aucune couleur de barre, charge comme produit', function () {
    expect(ComparaisonBudgetaire::couleurBarre(0.0, true))->toBeNull();
    expect(ComparaisonBudgetaire::couleurBarre(0.0, false))->toBeNull();
});

it('l ecart d une recette non encaissee reste defavorable malgre la barre vide', function () {
    // L'alerte survit : seule la barre cesse de crier.
    $ecart = ComparaisonBudgetaire::ecart(500.0, 0.0);
    expect($ecart)->toBe(-500.0);
    expect(ComparaisonBudgetaire::ecartEstFavorable($ecart, false))->toBeFalse();
});

// écart() — delta brut, IDENTIQUE pour une charge et un produit : réalisé -
// prévu, point. C'est ecartEstFavorable() qui porte l'appréciation, jamais
// ecart() lui-même. Les trois cas ci-dessous sont ceux du propriétaire,
// exactement : mêmes montants (600/670 en recette et en dépense) pour bien
// montrer que le NOMBRE ne bouge pas — seule la COULEUR change de sens.

it('ecart : recette prevu 600 / realise 670 vaut +70', function () {
    expect(ComparaisonBudgetaire::ecart(600.0, 670.0))->toBe(70.0);
});

it('ecart : depense prevu 600 / realise 670 vaut aussi +70 (meme delta brut)', function () {
    expect(ComparaisonBudgetaire::ecart(600.0, 670.0))->toBe(70.0);
});

it('ecart : depense prevu 600 / realise 530 vaut -70', function () {
    expect(ComparaisonBudgetaire::ecart(600.0, 530.0))->toBe(-70.0);
});

// ecartEstFavorable() — c'est ELLE qui distingue charge et produit, jamais
// ecart(). Reprend les trois cas ci-dessus pour vérifier l'appréciation.

it('ecartEstFavorable : recette 600/670 (+70) est favorable', function () {
    $ecart = ComparaisonBudgetaire::ecart(600.0, 670.0);

    expect(ComparaisonBudgetaire::ecartEstFavorable($ecart, false))->toBeTrue();
});

it('ecartEstFavorable : depense 600/670 (+70) est defavorable', function () {
    // Dépenser 70 de plus que prévu est une mauvaise nouvelle, même montant
    // que le cas recette ci-dessus mais appréciation opposée.
    $ecart = ComparaisonBudgetaire::ecart(600.0, 670.0);

    expect(ComparaisonBudgetaire::ecartEstFavorable($ecart, true))->toBeFalse();
});

it('ecartEstFavorable : depense 600/530 (-70) est favorable', function () {
    $ecart = ComparaisonBudgetaire::ecart(600.0, 530.0);

    expect(ComparaisonBudgetaire::ecartEstFavorable($ecart, true))->toBeTrue();
});
