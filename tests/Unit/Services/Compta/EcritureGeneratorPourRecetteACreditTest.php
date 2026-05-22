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

/**
 * Récupère le compte système par numero_pcg pour le tenant courant.
 */
function compteSystemeCredit(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

/**
 * Crée un compte produit classe 7 pour le tenant courant.
 */
function compte706Credit(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706c'.$suffix,
        'intitule' => 'Cotisations et adhésions (crédit) '.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

/**
 * Crée un tiers pour le tenant courant.
 */
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
// Cas 1 : Recette à crédit normale → T1 : 411 D X (tiers) / 706 C X
// ---------------------------------------------------------------------------
test('pourRecetteACredit crée T1 ligne 411 débit avec tiers / 706 crédit sans tiers', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('A');
    $compte411 = compteSystemeCredit('411');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 120.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
        libelle: 'Facture adhésion annuelle',
    );

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->lignes)->toHaveCount(2);

    $ligne411 = $transaction->lignes->firstWhere('compte_id', $compte411->id);
    $ligneProduit = $transaction->lignes->firstWhere('compte_id', $compteProduit->id);

    expect($ligne411)->not->toBeNull();
    expect($ligneProduit)->not->toBeNull();

    // 411 est au débit avec le tiers
    expect((float) $ligne411->debit)->toBe(120.00);
    expect((float) $ligne411->credit)->toBe(0.00);
    expect((int) $ligne411->tiers_id)->toBe((int) $tiers->id);

    // 706 est au crédit sans tiers
    expect((float) $ligneProduit->debit)->toBe(0.00);
    expect((float) $ligneProduit->credit)->toBe(120.00);
    expect($ligneProduit->tiers_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 2 : T1 équilibrée — equilibree = TRUE, ∑D = ∑C = X, type_ecriture = 'normale'
// ---------------------------------------------------------------------------
test('pourRecetteACredit produit transaction equilibree=TRUE, ∑D=∑C, type_ecriture=normale', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('B');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 250.00,
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
// Cas 3 : Pas de portage — exactement 2 lignes, pas de T2
// ---------------------------------------------------------------------------
test('pourRecetteACredit crée exactement 2 lignes (pas de portage, pas de T2)', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('C');

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 80.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    // Exactement 2 lignes sur la transaction
    expect($transaction->lignes)->toHaveCount(2);

    // Exactement 1 transaction créée (pas de T2)
    expect(Transaction::count())->toBe($transactionsBefore + 1);
});

// ---------------------------------------------------------------------------
// Cas 4 : Solde ouvert 411 du tiers = X après création
// ---------------------------------------------------------------------------
test('pourRecetteACredit laisse un solde ouvert 411 du tiers égal au montant', function () {
    $tiers = tiersCourantCredit();
    $compteProduit = compte706Credit('D');
    $compte411 = compteSystemeCredit('411');

    $generator = app(EcritureGenerator::class);

    $generator->pourRecetteACredit(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 175.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    // Solde 411 du tiers = ∑débit - ∑crédit sur les lignes 411 de ce tiers
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

    // Créer un tiers dans le tenant B
    TenantContext::boot($associationB);
    SystemeSeeder::seed(); // seed les comptes système pour B aussi
    $tiersB = Tiers::factory()->create(['association_id' => $associationB->id]);
    TenantContext::boot($associationA); // revenir au tenant A

    // Bypass scope pour accéder au tiers B depuis le contexte A
    $tiersBBypassed = Tiers::withoutGlobalScopes()->find($tiersB->id);

    $compteProduit = compte706Credit('E');

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiersBBypassed,
        compteProduit: $compteProduit,
        montant: 100.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(TenantBoundaryException::class);

    // Aucune transaction créée (rollback)
    expect(Transaction::count())->toBe($transactionsBefore);
});

// ---------------------------------------------------------------------------
// Cas 6 : Compte produit classe ≠ 7 → CompteIncorrectException + rollback
// ---------------------------------------------------------------------------
test('pourRecetteACredit lève CompteIncorrectException si compteProduit classe ≠ 7', function () {
    $tiers = tiersCourantCredit();

    // Compte classe 6
    $compteClasse6 = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '606c',
        'intitule' => 'Achats (classe 6)',
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        compteProduit: $compteClasse6,
        montant: 100.00,
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
        compteProduit: $compteProduit,
        montant: 0.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: -50.00,
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
        compteProduit: $compteProduit,
        montant: 60.00,
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
        compteProduit: $compteProduit,
        montant: 45.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    expect($transaction->mode_paiement)->toBeNull();
});
