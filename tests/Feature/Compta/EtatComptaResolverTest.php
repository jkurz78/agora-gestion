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

it('déduit l’étape courante du premier blocage, dans l’ordre de l’énumération', function (): void {
    // Les clés sont insérées dans l'ordre inverse de l'énumération : l'étape
    // retournée doit suivre l'ordre déclaré, pas l'ordre d'insertion.
    $etat = new EtatCompta([
        EtapeCompta::ReconciliationRequise->value => 'Statuts en désaccord.',
        EtapeCompta::BackfillRequis->value => 'Écritures incomplètes.',
    ]);

    expect($etat->etape())->toBe(EtapeCompta::BackfillRequis)
        ->and($etat->estOperationnel())->toBeFalse();
});

it('est opérationnel exactement quand aucun blocage n’est détecté', function (): void {
    $ok = new EtatCompta([]);

    expect($ok->estOperationnel())->toBeTrue()
        ->and($ok->etape())->toBe(EtapeCompta::Operationnel)
        ->and($ok->causes())->toBe('');
});

it('répond sur une condition précise, pas seulement sur la première', function (): void {
    // Deux blocages : l'étape courante est le premier, mais le second doit rester
    // interrogeable — sinon une garde qui vise la réconciliation deviendrait
    // aveugle dès qu'un blocage antérieur apparaît.
    $etat = new EtatCompta([
        EtapeCompta::BackfillRequis->value => '3 transaction(s) ne sont pas converties en partie double.',
        EtapeCompta::ReconciliationRequise->value => '2 transaction(s) portent un statut en désaccord avec le grand livre.',
    ]);

    expect($etat->exige(EtapeCompta::BackfillRequis))->toBeTrue()
        ->and($etat->exige(EtapeCompta::ReconciliationRequise))->toBeTrue()
        ->and($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});

it('concatène ses causes en phrases lisibles, sans jamais prescrire de commande', function (): void {
    $etat = new EtatCompta([
        EtapeCompta::BackfillRequis->value => '3 transaction(s) ne sont pas converties en partie double.',
        EtapeCompta::ReconciliationRequise->value => '2 transaction(s) portent un statut en désaccord avec le grand livre.',
    ]);

    expect($etat->causes())
        ->toBe('3 transaction(s) ne sont pas converties en partie double. 2 transaction(s) portent un statut en désaccord avec le grand livre.')
        ->not->toContain('artisan');
});

it('refuse une clé de blocage inconnue', function (): void {
    // Une clé mal orthographiée serait affichée par le diagnostic mais invisible
    // d'exige() : la garde passerait sans rien signaler.
    expect(fn () => new EtatCompta(['backfil_requis' => 'Coquille.']))
        ->toThrow(InvalidArgumentException::class, 'backfil_requis');
});

it('refuse Operationnel comme clé de blocage', function (): void {
    expect(fn () => new EtatCompta([EtapeCompta::Operationnel->value => 'Contradiction.']))
        ->toThrow(InvalidArgumentException::class);
});
