<?php

declare(strict_types=1);

// Au 1er octobre, toutes les recettes budgetees sont a 0 % encaisse et
// sortaient en rouge fonce — le palier le plus bas de la rampe. L'ecran entier
// etait maximalement alarmant, donc plus rien n'alertait.
//
// La barre dit l'avancement, le nombre porte le jugement : realise nul, barre
// vide ; l'ecart chiffre, lui, garde sa couleur.

use App\Support\ComparaisonBudgetaire;

it('un realise nul ne rend aucune couleur de barre, charge comme produit', function (): void {
    expect(ComparaisonBudgetaire::couleurBarre(0.0, true))->toBeNull();
    expect(ComparaisonBudgetaire::couleurBarre(0.0, false))->toBeNull();
});

it('les paliers existants ne bougent pas', function (): void {
    // Charge : verte jusqu'a 103, puis la rampe s'assombrit.
    expect(ComparaisonBudgetaire::couleurBarre(50.0, true))->toBe('#2E7D32');
    expect(ComparaisonBudgetaire::couleurBarre(130.0, true))->toBe('#6E1E18');
    // Produit : symetrique inverse.
    expect(ComparaisonBudgetaire::couleurBarre(150.0, false))->toBe('#2E7D32');
    expect(ComparaisonBudgetaire::couleurBarre(50.0, false))->toBe('#6E1E18');
});

it('l ecart d une recette non encaissee reste defavorable', function (): void {
    // L'alerte survit : seule la barre cesse de crier.
    $ecart = ComparaisonBudgetaire::ecart(500.0, 0.0);
    expect($ecart)->toBe(-500.0);
    expect(ComparaisonBudgetaire::ecartEstFavorable($ecart, false))->toBeFalse();
});

it('un pourcentage negatif ne tombe pas dans la garde du realise nul', function (): void {
    // La garde teste `== 0.0`, pas `<= 0.0` : un contra-compte (709 au debit,
    // 609 au credit) produit un pourcentage negatif qui doit rester traite par
    // la rampe, tout en bas — pas par le court-circuit "barre vide".
    expect(ComparaisonBudgetaire::couleurBarre(-40.0, false))->toBe('#6E1E18');
    expect(ComparaisonBudgetaire::couleurBarre(-40.0, false))->not->toBeNull();
});
