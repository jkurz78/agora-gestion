<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Services\Compta\PlanComptableSelecteur;
use Livewire\Livewire;

/**
 * Bug 1 — le kit de comptes d'immobilisation doit être créé « à la demande »
 * à l'ouverture de la modale, pas seulement par les tests. Aucun compte de
 * classe 2 n'existe avant l'appel : rien dans ce beforeEach n'invoque
 * ImmobilisationComptesSeeder::seed().
 */
it('crée le kit de comptes à l’ouverture de la modale sur un tenant vierge', function (): void {
    expect(Compte::where('classe', 2)->count())->toBe(0);

    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal');

    expect(Compte::ofNumero('2188'))->not->toBeNull()
        ->and(Compte::ofNumero('6811'))->not->toBeNull();

    $numeros = PlanComptableSelecteur::groupesPourType('immobilisation')
        ->flatMap(fn (array $groupe) => $groupe['comptes']->pluck('numero_pcg'))
        ->all();

    expect($numeros)->toContain('2188');
});

it('reste idempotent en rouvrant la modale plusieurs fois', function (): void {
    Livewire::test(ImmobilisationIndex::class)
        ->call('ouvrirModal')
        ->call('fermerModal')
        ->call('ouvrirModal');

    expect(Compte::where('numero_pcg', '2188')->count())->toBe(1);
});
