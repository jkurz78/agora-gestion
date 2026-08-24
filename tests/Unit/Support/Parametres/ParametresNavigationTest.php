<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Support\Parametres\EcranParametre;
use App\Support\Parametres\ParametresNavigation;
use App\Support\Parametres\SectionParametres;
use Illuminate\Support\Facades\Route;

function invokePrivateStaticMethod(string $class, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs(null, $args);
}

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
    $correspondances = [];
    foreach (ParametresNavigation::sections() as $section) {
        foreach ($section->ecrans as $e) {
            if ($e->cle === $cle) {
                $correspondances[] = $e;
            }
        }
    }

    expect($correspondances)->toHaveCount(1, "Écran {$cle} absent ou dupliqué dans la taxonomie.");
    $ecran = $correspondances[0];

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

it('lève une exception si deux écrans partagent la même clé', function (): void {
    $sections = [
        new SectionParametres('section-test', 'Section test', 'Description', 'bi-test', [
            new EcranParametre('doublon', 'Écran un', 'parametres.association', 'bi-a', [RoleAssociation::Admin]),
            new EcranParametre('doublon', 'Écran deux', 'parametres.helloasso', 'bi-b', [RoleAssociation::Admin]),
        ]),
    ];

    expect(fn () => invokePrivateStaticMethod(ParametresNavigation::class, 'garantirClesUniques', [$sections]))
        ->toThrow(InvalidArgumentException::class, 'doublon');
});

it('lève une exception si deux sections partagent la même clé', function (): void {
    $sections = [
        new SectionParametres('doublon', 'Section un', 'Description', 'bi-a', [
            new EcranParametre('ecran-un', 'Écran un', 'parametres.association', 'bi-a', [RoleAssociation::Admin]),
        ]),
        new SectionParametres('doublon', 'Section deux', 'Description', 'bi-b', [
            new EcranParametre('ecran-deux', 'Écran deux', 'parametres.helloasso', 'bi-b', [RoleAssociation::Admin]),
        ]),
    ];

    expect(fn () => invokePrivateStaticMethod(ParametresNavigation::class, 'garantirClesUniques', [$sections]))
        ->toThrow(InvalidArgumentException::class, 'doublon');
});

it('l’arbre réel passe la garde d’unicité des clés', function (): void {
    expect(fn () => ParametresNavigation::sections())->not->toThrow(InvalidArgumentException::class);
});
