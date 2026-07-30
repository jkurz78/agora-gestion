<?php

declare(strict_types=1);

use App\Enums\EtapeCompta;
use App\Services\Compta\EtatCompta;

it('nomme chaque étape en français, sans jargon de migration', function (): void {
    expect(EtapeCompta::BackfillRequis->label())
        ->toBe('Écritures comptables incomplètes')
        ->and(EtapeCompta::RepriseInitialeRequise->label())
        ->toBe('Soldes d’ouverture non repris')
        ->and(EtapeCompta::ReconciliationRequise->label())
        ->toBe('Statuts de règlement à mettre à jour')
        ->and(EtapeCompta::Operationnel->label())
        ->toBe('Opérationnel');
});

it('ne porte aucune commande : le remède appartient à l’appelant', function (): void {
    expect(method_exists(EtapeCompta::class, 'geste'))->toBeFalse();

    foreach (EtapeCompta::cases() as $etape) {
        expect($etape->label())->not->toContain('artisan');
    }
});

it('expose l’étape, ses blocages et la nature opérationnelle', function (): void {
    $bloque = new EtatCompta(
        EtapeCompta::RepriseInitialeRequise,
        [EtapeCompta::RepriseInitialeRequise->value => '2 compte(s) bancaire(s) portent un solde historique jamais entré dans le grand livre.'],
    );

    expect($bloque->estOperationnel())->toBeFalse()
        ->and($bloque->blocages)->toHaveCount(1);

    $ok = new EtatCompta(EtapeCompta::Operationnel, []);

    expect($ok->estOperationnel())->toBeTrue()
        ->and($ok->blocages)->toBe([]);
});

it('répond sur une condition précise, pas seulement sur la première', function (): void {
    // Deux blocages : l'étape courante est le premier, mais le second doit rester
    // interrogeable — sinon une garde qui vise le backfill deviendrait aveugle
    // dès qu'un blocage antérieur apparaît.
    $etat = new EtatCompta(
        EtapeCompta::BackfillRequis,
        [
            EtapeCompta::BackfillRequis->value => '3 transaction(s) ne sont pas converties en partie double.',
            EtapeCompta::ReconciliationRequise->value => '2 transaction(s) portent un statut en désaccord avec le grand livre.',
        ],
    );

    expect($etat->exige(EtapeCompta::BackfillRequis))->toBeTrue()
        ->and($etat->exige(EtapeCompta::ReconciliationRequise))->toBeTrue()
        ->and($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});

it('énonce ses causes sans jamais prescrire de commande', function (): void {
    $etat = new EtatCompta(
        EtapeCompta::BackfillRequis,
        [EtapeCompta::BackfillRequis->value => '3 transaction(s) ne sont pas converties en partie double.'],
    );

    expect($etat->causes())
        ->toContain('3 transaction(s)')
        ->not->toContain('artisan');
});
