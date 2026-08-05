<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Famille;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;

it('crée le kit de comptes et les familles', function (): void {
    ImmobilisationComptesSeeder::seed();

    foreach (['2154', '2183', '2184', '2188', '28154', '28183', '28184', '28188', '6811'] as $numero) {
        expect(Compte::ofNumero($numero))->not->toBeNull("Le compte {$numero} devrait exister");
    }

    expect(Compte::ofNumero('2188')->classe)->toBe(2)
        ->and(Compte::ofNumero('6811')->classe)->toBe(6);

    foreach (['21', '28', '68'] as $code) {
        expect(Famille::where('code', $code)->exists())->toBeTrue("La famille {$code} devrait exister");
    }
});

it('est idempotent', function (): void {
    ImmobilisationComptesSeeder::seed();
    ImmobilisationComptesSeeder::seed();

    expect(Compte::where('numero_pcg', '2188')->count())->toBe(1)
        ->and(Famille::where('code', '21')->count())->toBe(1);
});

it('expose les comptes de classe 2 au sélecteur de ventilation', function (): void {
    ImmobilisationComptesSeeder::seed();

    $groupes = PlanComptableSelecteur::groupesPourType('immobilisation');
    $numeros = $groupes->flatMap(fn (array $g) => $g['comptes']->pluck('numero_pcg'))->all();

    expect($numeros)->toContain('2188')
        ->and($numeros)->not->toContain('6811');
});
