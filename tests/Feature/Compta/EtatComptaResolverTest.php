<?php

declare(strict_types=1);

use App\Enums\EtapeCompta;
use App\Enums\OrigineANouveau;
use App\Enums\StatutANouveau;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
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

it('exige le backfill quand des transactions ne sont pas en partie double', function (): void {
    $this->setupPartieDoubleContext();

    Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'equilibree' => false,
        'helloasso_order_id' => null,
    ]);

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

// etatComptaCreerReprise() a été déplacée dans tests/Pest.php : lancée seule
// via son chemin de fichier, cette suite ne charge pas les autres fichiers de
// test, donc une fonction globale déclarée ici ne serait pas disponible pour
// eux ; à l'inverse d'une exécution de suite complète ou filtrée par nom.

it('exige la reprise quand aucun solde historique n’est dans le grand livre', function (): void {
    $this->setupPartieDoubleContext();

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

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

it('n’exige rien d’une association qui démarre à zéro', function (): void {
    $this->setupPartieDoubleContext();

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    // Une association qui démarre à zéro n'a rien à reprendre : elle traverse
    // cette étape sans rien faire. C'est le cas nominal, pas une exception.
    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});

it('lève le blocage quand la reprise couvre le compte porteur', function (): void {
    $this->setupPartieDoubleContext();

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    etatComptaCreerReprise($this, [$this->compte512X]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeFalse();
});

it('bloque quand la reprise ne couvre qu’une partie des comptes porteurs', function (): void {
    $this->setupPartieDoubleContext();

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    // Séquence du 2026-07-29 : le compte courant est repris, le livret A ne
    // l'est pas — 24 010 € disparaissent à la clôture sans qu'aucune garde ne
    // le signale.
    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    $livretA = CompteBancaire::factory()->avecSoldeHistorique(24010.00)->create([
        'association_id' => $this->association->id,
    ]);
    Compte::factory()->numero('5122')->create([
        'association_id' => $this->association->id,
        'compte_bancaire_id' => $livretA->id,
    ]);

    etatComptaCreerReprise($this, [$this->compte512X]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeTrue()
        ->and($etat->causes())->toContain('1 compte');
});

it('bloque quand la reprise vise une année postérieure au premier exercice', function (): void {
    $this->setupPartieDoubleContext();

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);
    Exercice::create(['annee' => 2025, 'statut' => 'ouvert']);

    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    // L'année par défaut de compta:bootstrap-an est l'exercice courant : viser
    // 2025 alors que le premier exercice est 2024 est le piège le plus probable
    // en exploitation.
    etatComptaCreerReprise($this, [$this->compte512X], exerciceCible: 2025);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeTrue();
});

it('bloque quand un solde a été corrigé après la reprise', function (): void {
    $this->setupPartieDoubleContext();

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    etatComptaCreerReprise($this, [$this->compte512X]);

    // Séquence exacte du 2026-07-29 : date de solde rectifiée à 13:34, clôture
    // à 13:35 — le solde corrigé n'a jamais transité par la reprise.
    $this->travel(1)->minute();
    $this->compteBancaire->update(['solde_initial' => 2518.82]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeTrue();
});

it('bloque quand la reprise a été invalidée par une réouverture', function (): void {
    $this->setupPartieDoubleContext();

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    etatComptaCreerReprise($this, [$this->compte512X], statut: StatutANouveau::Invalidee);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeTrue();
});

it('ne prend pas un à-nouveau de clôture pour une reprise', function (): void {
    $this->setupPartieDoubleContext();

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    $this->compteBancaire->update(['solde_initial' => 2388.82]);

    etatComptaCreerReprise($this, [$this->compte512X], origine: OrigineANouveau::Cloture);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeTrue();
});

it('bloque un compte porteur dépourvu de compte 512X', function (): void {
    $this->setupPartieDoubleContext();

    Exercice::create(['annee' => 2024, 'statut' => 'ouvert']);

    // Livret sans compte 512X : la reprise parcourt le plan comptable et ne
    // peut par construction pas le voir.
    CompteBancaire::factory()->avecSoldeHistorique(500.00)->create([
        'association_id' => $this->association->id,
    ]);

    etatComptaCreerReprise($this, [$this->compte512X]);

    $etat = app(EtatComptaResolver::class)->pourTenantCourant();

    expect($etat->exige(EtapeCompta::RepriseInitialeRequise))->toBeTrue();
});
