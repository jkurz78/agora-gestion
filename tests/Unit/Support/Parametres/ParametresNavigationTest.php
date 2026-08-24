<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Support\Parametres\ParametresNavigation;
use Illuminate\Support\Facades\Route;

it('décrit quatre sections et douze écrans', function (): void {
    $sections = ParametresNavigation::sections();

    expect($sections)->toHaveCount(4);

    $total = array_sum(array_map(fn ($s): int => count($s->ecrans), $sections));
    expect($total)->toBe(12);
});

it('chaque écran pointe une route déclarée', function (): void {
    foreach (ParametresNavigation::sections() as $section) {
        foreach ($section->ecrans as $ecran) {
            expect(Route::has($ecran->route))->toBeTrue(
                "La route {$ecran->route} de l'écran {$ecran->cle} n'existe pas.",
            );
        }
    }
})->todo('Routes créées en Task 6 à 8');

it('applique la matrice de droits de la spec', function (string $cle, array $attendus): void {
    $ecran = null;
    foreach (ParametresNavigation::sections() as $section) {
        foreach ($section->ecrans as $e) {
            if ($e->cle === $cle) {
                $ecran = $e;
            }
        }
    }

    expect($ecran)->not->toBeNull("Écran {$cle} absent de la taxonomie.");

    foreach ([RoleAssociation::Admin, RoleAssociation::Comptable, RoleAssociation::Gestionnaire, RoleAssociation::Consultation] as $role) {
        expect($ecran->accessiblePar($role))->toBe(
            in_array($role->value, $attendus, true),
            "{$cle} / {$role->value}",
        );
    }
})->with([
    ['informations', ['admin']],
    ['utilisateurs', ['admin']],
    ['liens-publics', ['admin', 'gestionnaire']],
    ['formules-adhesion', ['admin', 'gestionnaire']],
    ['recus-fiscaux', ['admin', 'comptable']],
    ['plan-comptable', ['admin', 'comptable']],
    ['affectations-comptables', ['admin', 'comptable']],
    ['facturation', ['admin', 'comptable']],
    ['helloasso', ['admin']],
    ['reception-documents', ['admin']],
    ['envoi-emails', ['admin']],
    ['ocr-ia', ['admin']],
]);
