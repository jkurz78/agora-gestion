<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\CompteIncorrectException;
use App\Exceptions\Compta\TenantBoundaryException;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Helpers locaux (suffixes distincts de ceux de RecetteComptant pour éviter
// les conflits de numero_pcg si les tests partagent la même DB de test)
// ---------------------------------------------------------------------------

/**
 * Crée ou récupère le compte système par numero_pcg pour le tenant courant.
 */
function compteSystemeD(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

/**
 * Crée un compte charge classe 6 pour le tenant courant.
 */
function compte607(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '607'.$suffix,
        'intitule' => 'Achats divers'.$suffix,
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

/**
 * Crée un compte bancaire physique 512X pour le tenant courant.
 */
function compte512DEP(string $suffix = 'BNP'): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '5121',
        'intitule' => 'Banque '.$suffix,
        'classe' => 5,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'iban' => 'FR76000000000000000000002',
    ]);
}

/**
 * Crée un tiers pour le tenant courant.
 */
function tiersCourantD(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

// ---------------------------------------------------------------------------
// beforeEach : seed des comptes système (5112 + 530 + 411 + 401)
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();

    // 530 est conditionnel — si le seed ne l'a pas créé on le crée manuellement.
    $has530 = Compte::where('numero_pcg', '530')
        ->where('association_id', TenantContext::currentId())
        ->exists();

    if (! $has530) {
        Compte::create([
            'association_id' => TenantContext::currentId(),
            'numero_pcg' => '530',
            'intitule' => 'Caisse (espèces)',
            'classe' => 5,
            'lettrable' => true,
            'actif' => true,
            'est_systeme' => true,
            'pour_inscriptions' => false,
        ]);
    }
});

// ---------------------------------------------------------------------------
// Cas 1 : Dépense chèque émis → ligne 607 D / ligne 512 C (PAS de 5112 miroir)
// ---------------------------------------------------------------------------
test('pourDepenseComptant chèque crée T1 ligne 607 débit / 512 crédit SANS 5112 miroir', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('A');
    $compteTreso = compte512DEP('BNP');
    $compte5112 = compteSystemeD('5112');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 120.00,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-01'),
        libelle: 'Achat fournitures chèque',
    );

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->lignes)->toHaveCount(2);

    $ligneCharge = $transaction->lignes->firstWhere('compte_id', $compteCharge->id);
    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);

    // Les 2 lignes attendues existent
    expect($ligneCharge)->not->toBeNull();
    expect($ligneTreso)->not->toBeNull();

    // 607 est au débit
    expect((float) $ligneCharge->debit)->toBe(120.00);
    expect((float) $ligneCharge->credit)->toBe(0.00);

    // 512 est au crédit
    expect((float) $ligneTreso->debit)->toBe(0.00);
    expect((float) $ligneTreso->credit)->toBe(120.00);

    // ASSERT explicite : aucun mouvement sur 5112 (asymétrie volontaire)
    $ligne5112 = $transaction->lignes->firstWhere('compte_id', $compte5112->id);
    expect($ligne5112)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 2 : Dépense CB → ligne 607 D / ligne 512 C
// ---------------------------------------------------------------------------
test('pourDepenseComptant CB crée T1 ligne 607 débit / 512 crédit', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('B');
    $compteTreso = compte512DEP('LCL');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 89.50,
        mode: ModePaiement::Cb,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-02'),
        libelle: 'Achat CB',
    );

    expect($transaction->lignes)->toHaveCount(2);

    $ligneCharge = $transaction->lignes->firstWhere('compte_id', $compteCharge->id);
    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);

    expect($ligneCharge)->not->toBeNull();
    expect($ligneTreso)->not->toBeNull();

    expect((float) $ligneCharge->debit)->toBe(89.50);
    expect((float) $ligneCharge->credit)->toBe(0.00);
    expect((float) $ligneTreso->debit)->toBe(0.00);
    expect((float) $ligneTreso->credit)->toBe(89.50);
});

// ---------------------------------------------------------------------------
// Cas 3 : Dépense espèces → ligne 607 D / ligne 530 C
// ---------------------------------------------------------------------------
test('pourDepenseComptant espèces crée T1 ligne 607 débit / 530 crédit', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('C');
    $compteTreso = compte512DEP('CA'); // ignoré pour espèces
    $compte530 = compteSystemeD('530');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 35.00,
        mode: ModePaiement::Especes,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-03'),
        libelle: 'Achat espèces',
    );

    expect($transaction->lignes)->toHaveCount(2);

    $ligneCharge = $transaction->lignes->firstWhere('compte_id', $compteCharge->id);
    $ligne530 = $transaction->lignes->firstWhere('compte_id', $compte530->id);

    expect($ligneCharge)->not->toBeNull();
    expect($ligne530)->not->toBeNull();

    expect((float) $ligneCharge->debit)->toBe(35.00);
    expect((float) $ligneCharge->credit)->toBe(0.00);
    expect((float) $ligne530->debit)->toBe(0.00);
    expect((float) $ligne530->credit)->toBe(35.00);
});

// ---------------------------------------------------------------------------
// Cas 4 : Dépense virement émis → ligne 607 D / ligne 512 C
// ---------------------------------------------------------------------------
test('pourDepenseComptant virement émis crée T1 ligne 607 débit / 512 crédit', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('D');
    $compteTreso = compte512DEP('BRED');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 450.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-04'),
        libelle: 'Loyer virement',
    );

    expect($transaction->lignes)->toHaveCount(2);

    $ligneCharge = $transaction->lignes->firstWhere('compte_id', $compteCharge->id);
    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);

    expect($ligneCharge)->not->toBeNull();
    expect($ligneTreso)->not->toBeNull();

    expect((float) $ligneCharge->debit)->toBe(450.00);
    expect((float) $ligneCharge->credit)->toBe(0.00);
    expect((float) $ligneTreso->debit)->toBe(0.00);
    expect((float) $ligneTreso->credit)->toBe(450.00);
});

// ---------------------------------------------------------------------------
// Cas 5 : T1 équilibrée — equilibree=TRUE, type_ecriture='normale', type=Depense
// ---------------------------------------------------------------------------
test('pourDepenseComptant produit une transaction equilibree=TRUE type_ecriture=normale type=Depense', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('E');
    $compteTreso = compte512DEP('SOC');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 200.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-05'),
    );

    expect($transaction->equilibree)->toBeTrue();
    expect($transaction->type_ecriture)->toBe('normale');
    expect($transaction->type)->toBe(TypeTransaction::Depense);

    $totalDebit = $transaction->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $transaction->lignes->sum(fn ($l) => (float) $l->credit);

    expect($totalDebit)->toBe(200.00);
    expect($totalCredit)->toBe(200.00);
});

// ---------------------------------------------------------------------------
// Cas 6 : Tiers porté côté trésorerie (PAS sur 607)
// ---------------------------------------------------------------------------
test('pourDepenseComptant porte tiers_id sur ligne trésorerie seulement (pas sur 607)', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('F');
    $compteTreso = compte512DEP('CAM');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 75.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-06'),
    );

    $ligneCharge = $transaction->lignes->firstWhere('compte_id', $compteCharge->id);
    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);

    // tiers_id sur la ligne de trésorerie
    expect((int) $ligneTreso->tiers_id)->toBe((int) $tiers->id);
    // PAS de tiers sur la ligne de charge
    expect($ligneCharge->tiers_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 7 : Compte charge classe ≠ 6 → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourDepenseComptant lève CompteIncorrectException si compteCharge classe ≠ 6', function () {
    $tiers = tiersCourantD();
    $compteTreso = compte512DEP('BNP2');

    // Compte classe 7 au lieu de classe 6
    $compteClasse7 = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706Z',
        'intitule' => 'Produits divers (classe 7)',
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteClasse7,
        montant: 100.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-07'),
    ))->toThrow(CompteIncorrectException::class);
});

// ---------------------------------------------------------------------------
// Cas 8 : Compte trésorerie non bancaire (modes Cheque/CB/Virement) → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourDepenseComptant lève CompteIncorrectException si compteTresorerie non bancaire pour chèque', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('G');

    // Compte classe 4 — pas un compte 512X
    $compteNonBancaire = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '401X',
        'intitule' => 'Fournisseurs divers',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 100.00,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteNonBancaire,
        date: new DateTimeImmutable('2026-05-08'),
    ))->toThrow(CompteIncorrectException::class);
});

test('pourDepenseComptant lève CompteIncorrectException si compteTresorerie non bancaire pour virement', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('H');

    $compteNonBancaire = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '411Y',
        'intitule' => 'Clients divers',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 100.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteNonBancaire,
        date: new DateTimeImmutable('2026-05-09'),
    ))->toThrow(CompteIncorrectException::class);
});

// ---------------------------------------------------------------------------
// Cas 9 : Montant ≤ 0 → \InvalidArgumentException
// ---------------------------------------------------------------------------
test('pourDepenseComptant lève InvalidArgumentException si montant ≤ 0', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('I');
    $compteTreso = compte512DEP('BNP3');

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 0.00,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-10'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->pourDepenseComptant(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: -50.00,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-10'),
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cas 10 : Tenant boundary — tiers d'un autre tenant → TenantBoundaryException + rollback
// ---------------------------------------------------------------------------
test('pourDepenseComptant lève TenantBoundaryException et rollback si tiers autre tenant', function () {
    $associationA = TenantContext::current();
    $associationB = Association::factory()->create();

    // Tiers appartenant au tenant B
    TenantContext::boot($associationB);
    $tiersB = Tiers::factory()->create(['association_id' => $associationB->id]);
    TenantContext::boot($associationA); // revenir au tenant A

    // Bypass scope pour accéder au tiers B depuis le contexte A
    $tiersBBypassed = Tiers::withoutGlobalScopes()->find($tiersB->id);

    $compteCharge = compte607('J');
    $compteTreso = compte512DEP('BNP4');

    $generator = app(EcritureGenerator::class);

    $transactionsBefore = Transaction::count();

    expect(fn () => $generator->pourDepenseComptant(
        tiers: $tiersBBypassed,
        compteCharge: $compteCharge,
        montant: 100.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-11'),
    ))->toThrow(TenantBoundaryException::class);

    // Aucune transaction créée (rollback)
    expect(Transaction::count())->toBe($transactionsBefore);
});
