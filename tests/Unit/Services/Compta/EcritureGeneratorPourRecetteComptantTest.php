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
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée ou récupère le compte système par numero_pcg pour le tenant courant.
 */
function compteSysteme(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

/**
 * Crée un compte produit classe 7 pour le tenant courant.
 */
function compte706(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706'.$suffix,
        'intitule' => 'Cotisations et adhésions'.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

/**
 * Crée un compte bancaire physique 512X pour le tenant courant.
 */
function compte512BNP(string $suffix = 'BNP'): Compte
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
        'iban' => 'FR76000000000000000000001',
    ]);
}

/**
 * Crée un tiers pour le tenant courant.
 */
function tiersCourant(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

// ---------------------------------------------------------------------------
// beforeEach : seed des comptes système (5112 + 530 + 411 + 401)
// ---------------------------------------------------------------------------

beforeEach(function () {
    // La migration SystemeSeeder est rejouée dans les tests pour garantir
    // que 5112 et 530 existent pour le tenant courant.
    // 530 est conditionnel (nécessite une transaction mode espèces existante)
    // — on le crée directement ici pour simplifier les tests.
    SystemeSeeder::seed();

    // 530 est conditionnel (EXISTS espèces) — si le seed ne l'a pas créé
    // on le crée manuellement pour le tenant courant.
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
// Cas 1 : Recette comptant chèque → ligne 5112 D / ligne 706 C
// ---------------------------------------------------------------------------
test('pourRecetteComptant chèque crée T1 ligne 5112 débit / 706 crédit', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('A');
    $compteTreso = compte512BNP(); // ignoré pour chèque, mais obligatoire en signature
    $compte5112 = compteSysteme('5112');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 150.00,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-01'),
        libelle: 'Adhésion 2026',
    );

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->lignes)->toHaveCount(2);

    $lignePortage = $transaction->lignes->firstWhere('compte_id', $compte5112->id);
    $ligneProduit = $transaction->lignes->firstWhere('compte_id', $compteProduit->id);

    expect($lignePortage)->not->toBeNull();
    expect($ligneProduit)->not->toBeNull();

    // 5112 est au débit, 706 est au crédit
    expect((float) $lignePortage->debit)->toBe(150.00);
    expect((float) $lignePortage->credit)->toBe(0.00);
    expect((float) $ligneProduit->debit)->toBe(0.00);
    expect((float) $ligneProduit->credit)->toBe(150.00);
});

// ---------------------------------------------------------------------------
// Cas 2 : Recette comptant espèces → ligne 530 D / ligne 706 C
// ---------------------------------------------------------------------------
test('pourRecetteComptant espèces crée T1 ligne 530 débit / 706 crédit', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('B');
    $compteTreso = compte512BNP('LCL'); // ignoré pour espèces
    $compte530 = compteSysteme('530');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 50.00,
        mode: ModePaiement::Especes,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-02'),
        libelle: 'Don espèces',
    );

    expect($transaction->lignes)->toHaveCount(2);

    $lignePortage = $transaction->lignes->firstWhere('compte_id', $compte530->id);
    $ligneProduit = $transaction->lignes->firstWhere('compte_id', $compteProduit->id);

    expect($lignePortage)->not->toBeNull();
    expect($ligneProduit)->not->toBeNull();

    expect((float) $lignePortage->debit)->toBe(50.00);
    expect((float) $lignePortage->credit)->toBe(0.00);
    expect((float) $ligneProduit->debit)->toBe(0.00);
    expect((float) $ligneProduit->credit)->toBe(50.00);
});

// ---------------------------------------------------------------------------
// Cas 3 : Recette virement → ligne 512BNP D / ligne 706 C
// ---------------------------------------------------------------------------
test('pourRecetteComptant virement crée T1 ligne 512X débit / 706 crédit', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('C');
    $compteTreso = compte512BNP('BRED');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 300.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-03'),
        libelle: 'Subvention virement',
    );

    expect($transaction->lignes)->toHaveCount(2);

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    $ligneProduit = $transaction->lignes->firstWhere('compte_id', $compteProduit->id);

    expect($ligneTreso)->not->toBeNull();
    expect($ligneProduit)->not->toBeNull();

    expect((float) $ligneTreso->debit)->toBe(300.00);
    expect((float) $ligneTreso->credit)->toBe(0.00);
    expect((float) $ligneProduit->debit)->toBe(0.00);
    expect((float) $ligneProduit->credit)->toBe(300.00);
});

// ---------------------------------------------------------------------------
// Cas 4 : Recette CB HelloAsso → ligne 512X D / ligne 706 C
// ---------------------------------------------------------------------------
test('pourRecetteComptant CB crée T1 ligne 512X débit / 706 crédit', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('D');
    $compteTreso = compte512BNP('HelloAsso');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 25.00,
        mode: ModePaiement::Cb,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-04'),
        libelle: 'Inscription HelloAsso CB',
    );

    expect($transaction->lignes)->toHaveCount(2);

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    $ligneProduit = $transaction->lignes->firstWhere('compte_id', $compteProduit->id);

    expect($ligneTreso)->not->toBeNull();
    expect($ligneProduit)->not->toBeNull();

    expect((float) $ligneTreso->debit)->toBe(25.00);
    expect((float) $ligneTreso->credit)->toBe(0.00);
    expect((float) $ligneProduit->debit)->toBe(0.00);
    expect((float) $ligneProduit->credit)->toBe(25.00);
});

// ---------------------------------------------------------------------------
// Cas 4b : Prélèvement → même comportement que Virement (compte 512X)
// ---------------------------------------------------------------------------
test('pourRecetteComptant prélèvement crée T1 ligne 512X débit / 706 crédit', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('E');
    $compteTreso = compte512BNP('CIC');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 12.50,
        mode: ModePaiement::Prelevement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-05'),
    );

    expect($transaction->lignes)->toHaveCount(2);

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    $ligneProduit = $transaction->lignes->firstWhere('compte_id', $compteProduit->id);

    expect($ligneTreso)->not->toBeNull();
    expect($ligneProduit)->not->toBeNull();

    expect((float) $ligneTreso->debit)->toBe(12.50);
    expect((float) $ligneProduit->credit)->toBe(12.50);
});

// ---------------------------------------------------------------------------
// Cas 5 : T1 équilibrée — equilibree = TRUE, ∑D = ∑C = montant
// ---------------------------------------------------------------------------
test('pourRecetteComptant produit une transaction equilibree=TRUE avec ∑D=∑C', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('F');
    $compteTreso = compte512BNP('SOC');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 200.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-06'),
    );

    expect($transaction->equilibree)->toBeTrue();

    $totalDebit = $transaction->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $transaction->lignes->sum(fn ($l) => (float) $l->credit);

    expect($totalDebit)->toBe(200.00);
    expect($totalCredit)->toBe(200.00);
});

// ---------------------------------------------------------------------------
// Cas 6 : tiers_id porté sur la ligne de portage/trésorerie, PAS sur 706
// ---------------------------------------------------------------------------
test('pourRecetteComptant porte tiers_id sur ligne de portage seulement', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('G');
    $compteTreso = compte512BNP('CAM');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 75.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-07'),
    );

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    $ligneProduit = $transaction->lignes->firstWhere('compte_id', $compteProduit->id);

    // tiers_id sur la ligne de portage/trésorerie
    expect((int) $ligneTreso->tiers_id)->toBe((int) $tiers->id);
    // PAS de tiers sur la ligne 706
    expect($ligneProduit->tiers_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 7 : type_ecriture = 'normale' + type = Recette sur Transaction
// ---------------------------------------------------------------------------
test("pourRecetteComptant crée une transaction avec type_ecriture='normale'", function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('H');
    $compteTreso = compte512BNP('CRE');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 500.00,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-08'),
    );

    expect($transaction->type_ecriture)->toBe('normale');
    expect($transaction->type)->toBe(TypeTransaction::Recette);
});

// ---------------------------------------------------------------------------
// Cas 8 : Tenant boundary — tiers d'un autre tenant → TenantBoundaryException
// ---------------------------------------------------------------------------
test('pourRecetteComptant lève TenantBoundaryException et rollback si tiers autre tenant', function () {
    $associationA = TenantContext::current();
    $associationB = Association::factory()->create();

    // Tiers appartenant au tenant B
    TenantContext::boot($associationB);
    $tiersB = Tiers::factory()->create(['association_id' => $associationB->id]);
    TenantContext::boot($associationA); // revenir au tenant A

    // Bypass scope pour accéder au tiers B depuis le contexte A
    $tiersBBypassed = Tiers::withoutGlobalScopes()->find($tiersB->id);

    $compteProduit = compte706('I');
    $compteTreso = compte512BNP('BNP2');

    $generator = app(EcritureGenerator::class);

    $transactionsBefore = Transaction::count();

    expect(fn () => $generator->pourRecetteComptant(
        tiers: $tiersBBypassed,
        compteProduit: $compteProduit,
        montant: 100.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-09'),
    ))->toThrow(TenantBoundaryException::class);

    // Aucune transaction créée (rollback)
    expect(Transaction::count())->toBe($transactionsBefore);
});

// ---------------------------------------------------------------------------
// Cas 9 : Compte produit classe ≠ 7 → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourRecetteComptant lève CompteIncorrectException si compteProduit classe ≠ 7', function () {
    $tiers = tiersCourant();
    $compteTreso = compte512BNP('BNP3');

    // Compte classe 6 au lieu de classe 7
    $compteClasse6 = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '606',
        'intitule' => 'Achats (classe 6)',
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteClasse6,
        montant: 100.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-10'),
    ))->toThrow(CompteIncorrectException::class);
});

// ---------------------------------------------------------------------------
// Cas 10 : Compte trésorerie non bancaire (virement) → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourRecetteComptant lève CompteIncorrectException si compteTresorerie non bancaire pour virement', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('J');

    // Compte 411 (classe 4) — pas un compte 512X
    $compteNonBancaire = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '411X',
        'intitule' => 'Clients divers',
        'classe' => 4,
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 100.00,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteNonBancaire,
        date: new DateTimeImmutable('2026-05-11'),
    ))->toThrow(CompteIncorrectException::class);
});

// ---------------------------------------------------------------------------
// Cas 11 : montant ≤ 0 → \InvalidArgumentException
// ---------------------------------------------------------------------------
test('pourRecetteComptant lève InvalidArgumentException si montant ≤ 0', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('K');
    $compteTreso = compte512BNP('BNP4');

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 0.00,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-12'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: -50.00,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-12'),
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cas 12 : Transaction recharge bien ses lignes (load)
// ---------------------------------------------------------------------------
test('pourRecetteComptant retourne la transaction avec ses lignes chargées', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('L');
    $compteTreso = compte512BNP('BNP5');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        compteProduit: $compteProduit,
        montant: 99.99,
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-13'),
        libelle: 'Test load',
    );

    // La relation est chargée (pas de lazy load supplémentaire)
    expect($transaction->relationLoaded('lignes'))->toBeTrue();
    expect($transaction->lignes)->toHaveCount(2);
});
