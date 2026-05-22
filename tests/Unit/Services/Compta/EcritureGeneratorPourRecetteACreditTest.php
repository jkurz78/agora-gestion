<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Exceptions\Compta\CompteIncorrectException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

function compteSystemeCredit(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

function compte706Credit(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg'     => '706c'.$suffix,
        'intitule'       => 'Cotisations et adhésions (crédit) '.$suffix,
        'classe'         => 7,
        'lettrable'      => false,
        'actif'          => true,
        'est_systeme'    => false,
        'pour_inscriptions' => false,
    ]);
}

function tiersCourantCredit(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

// ---------------------------------------------------------------------------
// beforeEach : seed des comptes système (411 minimum requis)
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();
});

// ---------------------------------------------------------------------------
// Cas 1 : Recette à crédit normale → T1 : 411 D X (tiers) / 706 C X (sans tiers)
// Schéma N+1 (N=1) — 2 lignes
// ---------------------------------------------------------------------------
test('pourRecetteACredit crée T1 ligne 411 débit avec tiers / 706 crédit sans tiers', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('A');
    $compte411 = compteSystemeCredit('411');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 120.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
        libelle: 'Facture adhésion annuelle',
    );

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->lignes)->toHaveCount(2);

    $ligne411 = $transaction->lignes->firstWhere('compte_id', $compte411->id);
    $ligneProduit = $transaction->lignes->firstWhere('compte_id', $compteProduit->id);

    expect($ligne411)->not->toBeNull();
    expect($ligneProduit)->not->toBeNull();

    // 411 D avec tiers
    expect((float) $ligne411->debit)->toBe(120.00);
    expect((float) $ligne411->credit)->toBe(0.00);
    expect((int) $ligne411->tiers_id)->toBe((int) $tiers->id);

    // 706 C sans tiers
    expect((float) $ligneProduit->debit)->toBe(0.00);
    expect((float) $ligneProduit->credit)->toBe(120.00);
    expect($ligneProduit->tiers_id)->toBeNull();

    // Pas de lettrage (créance ouverte)
    expect($ligne411->lettrage_code)->toBeNull('Créance ouverte — pas de lettrage à la constatation');
});

// ---------------------------------------------------------------------------
// Cas 2 : T1 équilibrée — equilibree = TRUE, ∑D = ∑C = total, type_ecriture = 'normale'
// ---------------------------------------------------------------------------
test('pourRecetteACredit produit transaction equilibree=TRUE, ∑D=∑C, type_ecriture=normale', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('B');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 250.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    expect($transaction->equilibree)->toBeTrue();
    expect($transaction->type_ecriture)->toBe('normale');
    expect($transaction->type)->toBe(TypeTransaction::Recette);

    $totalDebit = $transaction->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $transaction->lignes->sum(fn ($l) => (float) $l->credit);

    expect($totalDebit)->toBe(250.00);
    expect($totalCredit)->toBe(250.00);
});

// ---------------------------------------------------------------------------
// Cas 3 : N+1 lignes — 2 lignes pour N=1, 1 T créée
// ---------------------------------------------------------------------------
test('pourRecetteACredit crée exactement N+1 lignes (2 pour N=1), 1 transaction', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('C');

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 80.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    expect($transaction->lignes)->toHaveCount(2);
    expect(Transaction::count())->toBe($transactionsBefore + 1);
});

// ---------------------------------------------------------------------------
// Cas 4 : Solde ouvert 411 du tiers = montant après création
// ---------------------------------------------------------------------------
test('pourRecetteACredit laisse un solde ouvert 411 du tiers égal au montant', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('D');
    $compte411 = compteSystemeCredit('411');

    $generator = app(EcritureGenerator::class);

    $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 175.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    $solde411 = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');

    expect((float) $solde411)->toBe(175.00);
});

// ---------------------------------------------------------------------------
// Cas 5 : Tiers cross-tenant → TenantBoundaryException + rollback
// ---------------------------------------------------------------------------
test('pourRecetteACredit lève TenantBoundaryException et rollback si tiers autre tenant', function () {
    $associationA = TenantContext::current();
    $associationB = Association::factory()->create();

    TenantContext::boot($associationB);
    SystemeSeeder::seed();
    $tiersB = Tiers::factory()->create(['association_id' => $associationB->id]);
    TenantContext::boot($associationA);

    $tiersBBypassed = Tiers::withoutGlobalScopes()->find($tiersB->id);

    $compteProduit = compte706Credit('E');

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiersBBypassed,
        ventilations: [['compte' => $compteProduit, 'montant' => 100.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(TenantBoundaryException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
});

// ---------------------------------------------------------------------------
// Cas 6 : Compte produit classe ≠ 7 → CompteIncorrectException + rollback
// ---------------------------------------------------------------------------
test('pourRecetteACredit lève CompteIncorrectException si compte ventilation classe ≠ 7', function () {
    $tiers = tiersCourantCredit();

    $compteClasse6 = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg'     => '606c',
        'intitule'       => 'Achats (classe 6)',
        'classe'         => 6,
        'lettrable'      => false,
        'actif'          => true,
        'est_systeme'    => false,
        'pour_inscriptions' => false,
    ]);

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteClasse6, 'montant' => 100.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(CompteIncorrectException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
});

// ---------------------------------------------------------------------------
// Cas 7 : Montant ≤ 0 → \InvalidArgumentException + rollback
// ---------------------------------------------------------------------------
test('pourRecetteACredit lève InvalidArgumentException si montant ≤ 0', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('G');

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 0.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => -50.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
});

// ---------------------------------------------------------------------------
// Cas 8 : Date de constatation correctement appliquée sur transaction.date
// ---------------------------------------------------------------------------
test('pourRecetteACredit applique dateConstatation sur transaction.date', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('H');

    $generator = app(EcritureGenerator::class);

    $date = new DateTimeImmutable('2026-03-15');

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 60.00]],
        dateConstatation: $date,
        libelle: 'Constatation mars',
    );

    expect($transaction->date->format('Y-m-d'))->toBe('2026-03-15');
});

// ---------------------------------------------------------------------------
// Cas bonus : mode_paiement null sur la transaction (créance, pas encore payé)
// ---------------------------------------------------------------------------
test('pourRecetteACredit laisse mode_paiement null (créance pas encore payée)', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('I');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 45.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    expect($transaction->mode_paiement)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 9 (NOUVEAU) : Multi-ventilation 2 produits — T1 à 3 lignes (N=2)
// Schéma : 411 D total tiers / 706A C 80 / 706B C 20
// ---------------------------------------------------------------------------
test('pourRecetteACredit multi-ventilation crée T1 à 3 lignes (N=2, schéma N+1)', function () {
    $tiers = tiersCourantCredit();
    $compte706A = compte706Credit('MA');
    $compte706B = compte706Credit('MB');
    $compte411 = compteSystemeCredit('411');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compte706A, 'montant' => 80.00],
            ['compte' => $compte706B, 'montant' => 20.00],
        ],
        dateConstatation: new DateTimeImmutable('2026-05-21'),
        libelle: 'Facture multi-produits',
    );

    // N+1 = 2+1 = 3 lignes
    expect($transaction->lignes)->toHaveCount(3);

    // 411 D total 100, avec tiers
    $ligne411 = $transaction->lignes->firstWhere('compte_id', $compte411->id);
    expect((float) $ligne411->debit)->toBe(100.00);
    expect((int) $ligne411->tiers_id)->toBe((int) $tiers->id);
    expect($ligne411->lettrage_code)->toBeNull('Créance ouverte — pas de lettrage');

    // 706A C 80, sans tiers
    $ligne706A = $transaction->lignes->firstWhere('compte_id', $compte706A->id);
    expect((float) $ligne706A->credit)->toBe(80.00);
    expect($ligne706A->tiers_id)->toBeNull();

    // 706B C 20, sans tiers
    $ligne706B = $transaction->lignes->firstWhere('compte_id', $compte706B->id);
    expect((float) $ligne706B->credit)->toBe(20.00);
    expect($ligne706B->tiers_id)->toBeNull();

    // Équilibre ∑D = ∑C = 100
    $totalDebit = $transaction->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $transaction->lignes->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe(100.00);
    expect($totalCredit)->toBe(100.00);

    // Solde ouvert 411 du tiers = 100 (créance ouverte)
    $solde411 = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $solde411)->toBe(100.00);
});
