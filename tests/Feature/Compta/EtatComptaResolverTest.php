<?php

declare(strict_types=1);

use App\Enums\EtapeCompta;
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Models\ANouveauGeneration;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Services\Compta\EtatCompta;
use App\Services\Compta\EtatComptaResolver;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

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

/**
 * Met à zéro tous les soldes bancaires du tenant.
 *
 * À appeler APRÈS la création des fixtures : TransactionFactory pose
 * `compte_id => CompteBancaire::factory()`, donc chaque transaction créée sans
 * compte explicite frappe un second compte bancaire dont le solde est tiré au
 * hasard entre 0 et 10 000. Une règle du résolveur compte les comptes portant un
 * solde non nul : sans cette remise à zéro, les tests des autres règles
 * échoueraient sur un tirage aléatoire, donc par intermittence.
 */
function etatComptaIsolerSoldes(): void
{
    CompteBancaire::query()->update(['solde_initial' => 0]);
}

it('exige le backfill quand des transactions ne sont pas en partie double', function (): void {
    $this->setupPartieDoubleContext();

    Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => false,
        'helloasso_order_id' => null,
    ]);

    etatComptaIsolerSoldes();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::BackfillRequis))->toBeTrue()
        ->and($etat->etape())->toBe(EtapeCompta::BackfillRequis);
});

it('compte les opérations concernées dans sa cause, sans jargon de migration', function (): void {
    $this->setupPartieDoubleContext();

    Transaction::factory()->count(3)->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => false,
        'helloasso_order_id' => null,
    ]);

    etatComptaIsolerSoldes();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->causes())
        ->toContain('3 opération(s)')
        ->not->toContain('converti')
        ->not->toContain('backfill')
        ->not->toContain('artisan');
});

it('n’exige pas le backfill pour une transaction HelloAsso restée legacy', function (): void {
    $this->setupPartieDoubleContext();

    Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => false,
        'helloasso_order_id' => 'HA-12345',
    ]);

    etatComptaIsolerSoldes();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    // exige() plutôt que etape() : l'assertion reste juste et pour la bonne
    // raison quand d'autres règles s'ajouteront au résolveur.
    expect($etat->exige(EtapeCompta::BackfillRequis))->toBeFalse();
});

it('refuse de répondre sans TenantContext booté plutôt que de se dire opérationnel', function (): void {
    $this->setupPartieDoubleContext();
    TenantContext::clear();

    expect(fn () => app(EtatComptaResolver::class)->pourTenantCourant())
        ->toThrow(RuntimeException::class, 'TenantContext');
});

it('exige la reprise quand un solde bancaire historique n’est pas repris', function (): void {
    $this->setupPartieDoubleContext();

    // Les soldes réels de la préprod au moment du défaut du 2026-07-29.
    $this->compteBancaire->update([
        'solde_initial' => 2388.82,
        'date_solde_initial' => '2024-08-31',
    ]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeTrue()
        ->and($etat->etape())->toBe(EtapeCompta::RepriseInitialeRequise)
        ->and($etat->causes())->toContain('solde historique');
});

it('n’exige pas de reprise quand tous les soldes bancaires sont à zéro', function (): void {
    $this->setupPartieDoubleContext();
    etatComptaIsolerSoldes();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    // Une association qui démarre à zéro n'a rien à reprendre : elle traverse
    // cette étape sans rien faire. C'est le cas nominal, pas une exception.
    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});

it('considère la reprise faite quand une génération reprise_initiale est active', function (): void {
    $this->setupPartieDoubleContext();

    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    ANouveauGeneration::create([
        'association_id' => $this->association->id,
        'exercice_source' => 2023,
        'exercice_cible' => 2024,
        'transaction_id' => null,
        'origine' => OrigineANouveau::RepriseInitiale,
        'statut' => StatutANouveau::Active,
        'cree_par_id' => $this->user->id,
    ]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});
