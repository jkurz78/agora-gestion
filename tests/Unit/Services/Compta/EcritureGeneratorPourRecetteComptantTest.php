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
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

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
// Cas 1 : Recette comptant chèque — schéma N+3 : 411 D / 706 C / 5112 D / 411 C
// École 411 systématique (amendée 2026-05-22)
// ---------------------------------------------------------------------------
test('pourRecetteComptant chèque crée T1 4 lignes : 411 D / 706 C / 5112 D / 411 C', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('A');
    $compteTreso = compte512BNP();
    $compte411 = compteSysteme('411');
    $compte5112 = compteSysteme('5112');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 150.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-01'),
        libelle: 'Adhésion 2026',
    );

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->lignes)->toHaveCount(4);

    $lignes411 = $transaction->lignes->where('compte_id', $compte411->id)->values();
    $lignes706 = $transaction->lignes->where('compte_id', $compteProduit->id)->values();
    $lignes5112 = $transaction->lignes->where('compte_id', $compte5112->id)->values();

    expect($lignes411)->toHaveCount(2);
    expect($lignes706)->toHaveCount(1);
    expect($lignes5112)->toHaveCount(1);

    // 411 D tiers
    $ligne411D = $lignes411->firstWhere('debit', '>', 0);
    expect((float) $ligne411D->debit)->toBe(150.00);
    expect((float) $ligne411D->credit)->toBe(0.00);
    expect((int) $ligne411D->tiers_id)->toBe((int) $tiers->id);

    // 706 C sans tiers
    expect((float) $lignes706->first()->credit)->toBe(150.00);
    expect($lignes706->first()->tiers_id)->toBeNull();

    // 5112 D sans tiers
    expect((float) $lignes5112->first()->debit)->toBe(150.00);
    expect($lignes5112->first()->tiers_id)->toBeNull();

    // 411 C tiers
    $ligne411C = $lignes411->firstWhere('credit', '>', 0);
    expect((float) $ligne411C->credit)->toBe(150.00);
    expect((int) $ligne411C->tiers_id)->toBe((int) $tiers->id);
});

// ---------------------------------------------------------------------------
// Cas 1b : Auto-lettrage interne — les 2 lignes 411 partagent un lettrage_code
// ---------------------------------------------------------------------------
test('pourRecetteComptant chèque : les 2 lignes 411 sont lettrées (même code)', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('AL');
    $compteTreso = compte512BNP();
    $compte411 = compteSysteme('411');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 50.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-01'),
    );

    $lignes411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->get();

    expect($lignes411)->toHaveCount(2);

    $codes = $lignes411->pluck('lettrage_code')->filter()->unique();
    expect($codes)->toHaveCount(1, 'Les 2 lignes 411 doivent partager un seul code de lettrage');

    // La ligne 5112 D n'est PAS lettrée à ce stade
    $compte5112 = compteSysteme('5112');
    $ligne5112 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte5112->id)
        ->first();
    expect($ligne5112->lettrage_code)->toBeNull('5112 non lettrée — sera lettrée à la remise bancaire');
});

// ---------------------------------------------------------------------------
// Cas 2 : Recette comptant espèces → portage 530
// ---------------------------------------------------------------------------
test('pourRecetteComptant espèces crée T1 4 lignes avec portage 530', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('B');
    $compteTreso = compte512BNP('LCL');
    $compte530 = compteSysteme('530');
    $compte411 = compteSysteme('411');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 50.00]],
        mode: ModePaiement::Especes,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-02'),
    );

    expect($transaction->lignes)->toHaveCount(4);

    $ligne530 = $transaction->lignes->firstWhere('compte_id', $compte530->id);
    expect($ligne530)->not->toBeNull();
    expect((float) $ligne530->debit)->toBe(50.00);
    expect($ligne530->tiers_id)->toBeNull('530 ne porte pas de tiers — FEC conformité');
});

// ---------------------------------------------------------------------------
// Cas 3 : Recette virement → portage 512X
// ---------------------------------------------------------------------------
test('pourRecetteComptant virement crée T1 4 lignes avec portage 512X', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('C');
    $compteTreso = compte512BNP('BRED');
    $compte411 = compteSysteme('411');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 300.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-03'),
    );

    expect($transaction->lignes)->toHaveCount(4);

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    expect($ligneTreso)->not->toBeNull();
    expect((float) $ligneTreso->debit)->toBe(300.00);
    expect($ligneTreso->tiers_id)->toBeNull('512X ne porte pas de tiers — FEC conformité');

    // 411 D et 411 C au total 300
    $lignes411 = $transaction->lignes->where('compte_id', $compte411->id);
    expect($lignes411)->toHaveCount(2);
    expect((float) $lignes411->sum('debit'))->toBe(300.00);
    expect((float) $lignes411->sum('credit'))->toBe(300.00);
});

// ---------------------------------------------------------------------------
// Cas 4 : Recette CB → portage 512X
// ---------------------------------------------------------------------------
test('pourRecetteComptant CB crée T1 4 lignes avec portage 512X', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('D');
    $compteTreso = compte512BNP('HelloAsso');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 25.00]],
        mode: ModePaiement::Cb,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-04'),
    );

    expect($transaction->lignes)->toHaveCount(4);

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    expect($ligneTreso)->not->toBeNull();
    expect((float) $ligneTreso->debit)->toBe(25.00);
    expect($ligneTreso->tiers_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 4b : Prélèvement → même comportement que Virement (compte 512X)
// ---------------------------------------------------------------------------
test('pourRecetteComptant prélèvement crée T1 4 lignes avec portage 512X', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('E');
    $compteTreso = compte512BNP('CIC');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 12.50]],
        mode: ModePaiement::Prelevement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-05'),
    );

    expect($transaction->lignes)->toHaveCount(4);

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    expect($ligneTreso)->not->toBeNull();
    expect((float) $ligneTreso->debit)->toBe(12.50);
    expect($ligneTreso->tiers_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 5 : T1 équilibrée — ∑D = ∑C = 2 × total (schéma N+3)
// ---------------------------------------------------------------------------
test('pourRecetteComptant produit une transaction equilibree=TRUE avec ∑D=∑C = 2×total', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('F');
    $compteTreso = compte512BNP('SOC');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 200.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-06'),
    );

    expect($transaction->equilibree)->toBeTrue();

    $totalDebit = $transaction->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $transaction->lignes->sum(fn ($l) => (float) $l->credit);

    // Schéma N+3 : 411D 200 + 5xx D 200 = 400 débit ; 706 C 200 + 411 C 200 = 400 crédit
    expect($totalDebit)->toBe(400.00);
    expect($totalCredit)->toBe(400.00);
});

// ---------------------------------------------------------------------------
// Cas 6 : Tiers exclusif sur lignes 411 — pas de tiers sur 706 ni sur 5xx
// ---------------------------------------------------------------------------
test('pourRecetteComptant : tiers_id sur les 2 lignes 411 uniquement, pas sur 706 ni 5xx', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('G');
    $compteTreso = compte512BNP('CAM');
    $compte411 = compteSysteme('411');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 75.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-07'),
    );

    foreach ($transaction->lignes as $ligne) {
        if ((int) $ligne->compte_id === (int) $compte411->id) {
            // Les lignes 411 doivent porter le tiers
            expect((int) $ligne->tiers_id)->toBe((int) $tiers->id, 'Ligne 411 doit porter tiers_id');
        } else {
            // Toutes les autres lignes (706, 5xx) ne doivent PAS porter de tiers
            expect($ligne->tiers_id)->toBeNull("Ligne compte {$ligne->compte_id} ne doit pas porter de tiers");
        }
    }
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
        ventilations: [['compte' => $compteProduit, 'montant' => 500.00]],
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

    TenantContext::boot($associationB);
    $tiersB = Tiers::factory()->create(['association_id' => $associationB->id]);
    TenantContext::boot($associationA);

    $tiersBBypassed = Tiers::withoutGlobalScopes()->find($tiersB->id);

    $compteProduit = compte706('I');
    $compteTreso = compte512BNP('BNP2');

    $generator = app(EcritureGenerator::class);

    $transactionsBefore = Transaction::count();

    expect(fn () => $generator->pourRecetteComptant(
        tiers: $tiersBBypassed,
        ventilations: [['compte' => $compteProduit, 'montant' => 100.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-09'),
    ))->toThrow(TenantBoundaryException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
});

// ---------------------------------------------------------------------------
// Cas 9 : Compte ventilation classe ≠ 7 → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourRecetteComptant lève CompteIncorrectException si compte ventilation classe ≠ 7', function () {
    $tiers = tiersCourant();
    $compteTreso = compte512BNP('BNP3');

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
        ventilations: [['compte' => $compteClasse6, 'montant' => 100.00]],
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
        ventilations: [['compte' => $compteProduit, 'montant' => 100.00]],
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
        ventilations: [['compte' => $compteProduit, 'montant' => 0.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-12'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => -50.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-12'),
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cas 12 : Transaction retourne ses lignes chargées (load)
// ---------------------------------------------------------------------------
test('pourRecetteComptant retourne la transaction avec ses lignes chargées', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('L');
    $compteTreso = compte512BNP('BNP5');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 99.99]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-13'),
        libelle: 'Test load',
    );

    expect($transaction->relationLoaded('lignes'))->toBeTrue();
    expect($transaction->lignes)->toHaveCount(4);
});

// ---------------------------------------------------------------------------
// Cas 13 (NOUVEAU) : Multi-ventilation 60/40 sur 2 comptes 706A/706B
// Schéma 5 lignes : 411 D 100 / 706A C 60 / 706B C 40 / 5112 D 100 / 411 C 100
// ---------------------------------------------------------------------------
test('pourRecetteComptant multi-ventilation 60/40 crée T1 à 5 lignes (N=2)', function () {
    $tiers = tiersCourant();
    $compte706A = compte706('MA'); // 706MA
    $compte706B = compte706('MB'); // 706MB
    $compteTreso = compte512BNP('MBnp');
    $compte411 = compteSysteme('411');
    $compte5112 = compteSysteme('5112');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compte706A, 'montant' => 60.00],
            ['compte' => $compte706B, 'montant' => 40.00],
        ],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-15'),
        libelle: 'Cotisation adulte + enfant',
    );

    // N+3 = 2+3 = 5 lignes
    expect($transaction->lignes)->toHaveCount(5);

    // 411 D total 100, avec tiers
    $ligne411D = $transaction->lignes
        ->where('compte_id', $compte411->id)
        ->firstWhere('debit', '>', 0);
    expect((float) $ligne411D->debit)->toBe(100.00);
    expect((int) $ligne411D->tiers_id)->toBe((int) $tiers->id);

    // 706A C 60, sans tiers
    $ligne706A = $transaction->lignes->firstWhere('compte_id', $compte706A->id);
    expect((float) $ligne706A->credit)->toBe(60.00);
    expect($ligne706A->tiers_id)->toBeNull();

    // 706B C 40, sans tiers
    $ligne706B = $transaction->lignes->firstWhere('compte_id', $compte706B->id);
    expect((float) $ligne706B->credit)->toBe(40.00);
    expect($ligne706B->tiers_id)->toBeNull();

    // 5112 D total 100, sans tiers
    $ligne5112 = $transaction->lignes->firstWhere('compte_id', $compte5112->id);
    expect((float) $ligne5112->debit)->toBe(100.00);
    expect($ligne5112->tiers_id)->toBeNull();

    // 411 C total 100, avec tiers
    $ligne411C = $transaction->lignes
        ->where('compte_id', $compte411->id)
        ->firstWhere('credit', '>', 0);
    expect((float) $ligne411C->credit)->toBe(100.00);
    expect((int) $ligne411C->tiers_id)->toBe((int) $tiers->id);

    // Auto-lettrage interne des 2 lignes 411
    $codes = collect([$ligne411D, $ligne411C])->pluck('lettrage_code')->filter()->unique();
    expect($codes)->toHaveCount(1, 'Les 2 lignes 411 doivent partager un code de lettrage');

    // Équilibre : ∑D = ∑C = 200
    $totalDebit = $transaction->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $transaction->lignes->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe(200.00);
    expect($totalCredit)->toBe(200.00);

    // Solde ouvert 411 du tiers = 0 (lettré)
    $solde411 = TransactionLigne::where('compte_id', $compte411->id)
        ->where('tiers_id', $tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $solde411)->toBe(0.00, 'Solde ouvert 411 = 0 après recette comptant');
});

// ---------------------------------------------------------------------------
// Cas 14 : assertPasDeTiersSurClasse5 — aucune ligne classe 5 ne porte de tiers
// ---------------------------------------------------------------------------
test('pourRecetteComptant : aucune ligne classe 5 ne porte de tiers (FEC-conformité)', function () {
    $tiers = tiersCourant();
    $compteProduit = compte706('N');
    $compteTreso = compte512BNP('FEC');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 80.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-16'),
    );

    foreach ($transaction->lignes as $ligne) {
        $compte = Compte::find($ligne->compte_id);
        if ($compte->classe === 5) {
            expect($ligne->tiers_id)->toBeNull(
                "Ligne sur compte {$compte->numero_pcg} (classe 5) ne doit pas porter de tiers"
            );
        }
    }
});
