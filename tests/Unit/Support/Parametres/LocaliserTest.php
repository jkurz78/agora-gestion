<?php

declare(strict_types=1);

use App\Support\Parametres\ParametresNavigation;

it('retrouve la section et l’écran depuis un nom de route', function (): void {
    $position = ParametresNavigation::localiser('parametres.plan-comptable');

    expect($position)->not->toBeNull();
    expect($position['section']->cle)->toBe('comptabilite');
    expect($position['ecran']->cle)->toBe('plan-comptable');
});

it('retourne null pour une route hors Paramètres', function (): void {
    expect(ParametresNavigation::localiser('comptabilite.transactions'))->toBeNull();
});

it('retrouve une route de resource par son nom complet', function (): void {
    $position = ParametresNavigation::localiser('parametres.utilisateurs.index');

    expect($position)->not->toBeNull();
    expect($position['ecran']->cle)->toBe('utilisateurs');
});

it('refuse par défaut une sous-route de resource non déclarée explicitement', function (): void {
    // parametres.utilisateurs.store existe (Route::resource) mais n'est pas la route
    // déclarée par l'écran ('parametres.utilisateurs.index'). L'égalité stricte est
    // volontaire : localiser() ne fait AUCUNE correspondance de préfixe. La garde
    // serveur (Task 4) traite null comme « écran non déclaré → réservé aux admins ».
    // C'est un refus par défaut, jamais une ouverture par défaut : ne pas « corriger »
    // ce test en le faisant matcher, ce serait ouvrir les routes d'écriture de cet
    // écran (réservé admin) à tous les rôles qui ont accès à sa page de lecture.
    expect(ParametresNavigation::localiser('parametres.utilisateurs.store'))->toBeNull();
});
