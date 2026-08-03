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
// Helpers locaux (suffixes distincts pour éviter conflits de numero_pcg)
// ---------------------------------------------------------------------------

function compteSystemeD(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

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

function tiersCourantD(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

// ---------------------------------------------------------------------------
// beforeEach : seed des comptes système (5112 + 530 + 411 + 401)
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();

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
// Cas 1 : Dépense chèque émis — schéma N+3 :
// [6x D × N] / 401 C total tiers / 401 D total tiers / 512 C total (sans tiers)
// École 411 systématique (amendée 2026-05-22)
// ---------------------------------------------------------------------------
test('pourDepenseComptant chèque crée T1 4 lignes : 607 D / 401 C (tiers) / 401 D (tiers) / 512 C', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('A');
    $compteTreso = compte512DEP('BNP');
    $compte401 = compteSystemeD('401');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => 120.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-01'),
        libelle: 'Achat fournitures chèque',
    );

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect($transaction->lignes)->toHaveCount(4);

    // 607 D sans tiers
    $ligneCharge = $transaction->lignes->firstWhere('compte_id', $compteCharge->id);
    expect($ligneCharge)->not->toBeNull();
    expect((float) $ligneCharge->debit)->toBe(120.00);
    expect((float) $ligneCharge->credit)->toBe(0.00);
    expect($ligneCharge->tiers_id)->toBeNull('Ligne charge sans tiers');

    // 401 C tiers
    $lignes401 = $transaction->lignes->where('compte_id', $compte401->id)->values();
    expect($lignes401)->toHaveCount(2);

    $ligne401C = $lignes401->firstWhere('credit', '>', 0);
    expect((float) $ligne401C->credit)->toBe(120.00);
    expect((int) $ligne401C->tiers_id)->toBe((int) $tiers->id);

    // 401 D tiers
    $ligne401D = $lignes401->firstWhere('debit', '>', 0);
    expect((float) $ligne401D->debit)->toBe(120.00);
    expect((int) $ligne401D->tiers_id)->toBe((int) $tiers->id);

    // 512 C sans tiers (chèque émis → 512X direct, pas de 5112 miroir)
    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    expect($ligneTreso)->not->toBeNull();
    expect((float) $ligneTreso->credit)->toBe(120.00);
    expect($ligneTreso->tiers_id)->toBeNull('512X ne porte pas de tiers — FEC conformité');
});

// ---------------------------------------------------------------------------
// Cas 1b : Auto-lettrage interne — les 2 lignes 401 partagent un lettrage_code
// ---------------------------------------------------------------------------
test('pourDepenseComptant chèque : les 2 lignes 401 sont lettrées (même code)', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('AL');
    $compteTreso = compte512DEP('BNP_AL');
    $compte401 = compteSystemeD('401');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => 120.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-01'),
    );

    $lignes401 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte401->id)
        ->get();

    expect($lignes401)->toHaveCount(2);

    $codes = $lignes401->pluck('lettrage_code')->filter()->unique();
    expect($codes)->toHaveCount(1, 'Les 2 lignes 401 doivent partager un seul code de lettrage');

    // La ligne 512 C n'est PAS lettrée
    $ligneTreso = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compteTreso->id)
        ->first();
    expect($ligneTreso->lettrage_code)->toBeNull('512 non lettrée — sera pointée au rapprochement');
});

// ---------------------------------------------------------------------------
// Cas 2 : Dépense CB → portage 512X
// ---------------------------------------------------------------------------
test('pourDepenseComptant CB crée T1 4 lignes avec portage 512X', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('B');
    $compteTreso = compte512DEP('LCL');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => 89.50]],
        mode: ModePaiement::Cb,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-02'),
    );

    expect($transaction->lignes)->toHaveCount(4);

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    expect($ligneTreso)->not->toBeNull();
    expect((float) $ligneTreso->credit)->toBe(89.50);
    expect($ligneTreso->tiers_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 3 : Dépense espèces → portage 530
// ---------------------------------------------------------------------------
test('pourDepenseComptant espèces crée T1 4 lignes avec portage 530', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('C');
    $compteTreso = compte512DEP('CA');
    $compte530 = compteSystemeD('530');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => 35.00]],
        mode: ModePaiement::Especes,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-03'),
    );

    expect($transaction->lignes)->toHaveCount(4);

    $ligne530 = $transaction->lignes->firstWhere('compte_id', $compte530->id);
    expect($ligne530)->not->toBeNull();
    expect((float) $ligne530->credit)->toBe(35.00);
    expect($ligne530->tiers_id)->toBeNull('530 ne porte pas de tiers — FEC conformité');
});

// ---------------------------------------------------------------------------
// Cas 4 : Dépense virement émis → portage 512X
// ---------------------------------------------------------------------------
test('pourDepenseComptant virement émis crée T1 4 lignes avec portage 512X', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('D');
    $compteTreso = compte512DEP('BRED');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => 450.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-04'),
    );

    expect($transaction->lignes)->toHaveCount(4);

    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    expect($ligneTreso)->not->toBeNull();
    expect((float) $ligneTreso->credit)->toBe(450.00);
    expect($ligneTreso->tiers_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cas 5 : T1 équilibrée — equilibree=TRUE, type_ecriture='normale', type=Depense
// ∑D = ∑C = 2 × total (schéma N+3)
// ---------------------------------------------------------------------------
test('pourDepenseComptant produit une transaction equilibree=TRUE type_ecriture=normale type=Depense', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('E');
    $compteTreso = compte512DEP('SOC');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => 200.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-05'),
    );

    expect($transaction->equilibree)->toBeTrue();
    expect($transaction->type_ecriture)->toBe('normale');
    expect($transaction->type)->toBe(TypeTransaction::Depense);

    $totalDebit = $transaction->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $transaction->lignes->sum(fn ($l) => (float) $l->credit);

    // Schéma N+3 : 607 D 200 + 401 D 200 = 400 ; 401 C 200 + 512 C 200 = 400
    expect($totalDebit)->toBe(400.00);
    expect($totalCredit)->toBe(400.00);
});

// ---------------------------------------------------------------------------
// Cas 6 : Tiers exclusif sur lignes 401 — pas de tiers sur 607 ni sur 5xx
// ---------------------------------------------------------------------------
test('pourDepenseComptant : tiers_id sur les 2 lignes 401 uniquement, pas sur 607 ni 5xx', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('F');
    $compteTreso = compte512DEP('CAM');
    $compte401 = compteSystemeD('401');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => 75.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-06'),
    );

    foreach ($transaction->lignes as $ligne) {
        if ((int) $ligne->compte_id === (int) $compte401->id) {
            expect((int) $ligne->tiers_id)->toBe((int) $tiers->id, 'Ligne 401 doit porter tiers_id');
        } else {
            expect($ligne->tiers_id)->toBeNull("Ligne compte {$ligne->compte_id} ne doit pas porter de tiers");
        }
    }
});

// ---------------------------------------------------------------------------
// Cas 7 : Compte charge classe ≠ 6 → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourDepenseComptant lève CompteIncorrectException si compte ventilation classe ≠ 6', function () {
    $tiers = tiersCourantD();
    $compteTreso = compte512DEP('BNP2');

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
        ventilations: [['compte' => $compteClasse7, 'montant' => 100.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-07'),
    ))->toThrow(CompteIncorrectException::class);
});

// ---------------------------------------------------------------------------
// Cas 8 : Compte trésorerie non bancaire → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourDepenseComptant lève CompteIncorrectException si compteTresorerie non bancaire pour chèque', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('G');

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
        ventilations: [['compte' => $compteCharge, 'montant' => 100.00]],
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
        ventilations: [['compte' => $compteCharge, 'montant' => 100.00]],
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
        ventilations: [['compte' => $compteCharge, 'montant' => 0.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-10'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => -50.00]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-10'),
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cas 10 : Tenant boundary → TenantBoundaryException + rollback
// ---------------------------------------------------------------------------
test('pourDepenseComptant lève TenantBoundaryException et rollback si tiers autre tenant', function () {
    $associationA = TenantContext::current();
    $associationB = Association::factory()->create();

    TenantContext::boot($associationB);
    $tiersB = Tiers::factory()->create(['association_id' => $associationB->id]);
    TenantContext::boot($associationA);

    $tiersBBypassed = Tiers::withoutGlobalScopes()->find($tiersB->id);

    $compteCharge = compte607('J');
    $compteTreso = compte512DEP('BNP4');

    $generator = app(EcritureGenerator::class);

    $transactionsBefore = Transaction::count();

    expect(fn () => $generator->pourDepenseComptant(
        tiers: $tiersBBypassed,
        ventilations: [['compte' => $compteCharge, 'montant' => 100.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-11'),
    ))->toThrow(TenantBoundaryException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
});

// ---------------------------------------------------------------------------
// Cas 11 (NOUVEAU) : Multi-ventilation 70/30 sur 2 comptes 607A/607B
// Schéma 5 lignes : 607A D 70 / 607B D 30 / 401 C 100 tiers / 401 D 100 tiers / 512 C 100
// ---------------------------------------------------------------------------
test('pourDepenseComptant multi-ventilation 70/30 crée T1 à 5 lignes (N=2)', function () {
    $tiers = tiersCourantD();
    $compte607A = compte607('MA');
    $compte607B = compte607('MB');
    $compteTreso = compte512DEP('MBnp');
    $compte401 = compteSystemeD('401');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compte607A, 'montant' => 70.00],
            ['compte' => $compte607B, 'montant' => 30.00],
        ],
        mode: ModePaiement::Cheque,
        compteTresorerie: $compteTreso,
        date: new DateTimeImmutable('2026-05-15'),
        libelle: 'Achat matériaux + transport',
    );

    // N+3 = 2+3 = 5 lignes
    expect($transaction->lignes)->toHaveCount(5);

    // 607A D 70, sans tiers
    $ligne607A = $transaction->lignes->firstWhere('compte_id', $compte607A->id);
    expect((float) $ligne607A->debit)->toBe(70.00);
    expect($ligne607A->tiers_id)->toBeNull();

    // 607B D 30, sans tiers
    $ligne607B = $transaction->lignes->firstWhere('compte_id', $compte607B->id);
    expect((float) $ligne607B->debit)->toBe(30.00);
    expect($ligne607B->tiers_id)->toBeNull();

    // 401 C total 100, avec tiers
    $ligne401C = $transaction->lignes->where('compte_id', $compte401->id)->firstWhere('credit', '>', 0);
    expect((float) $ligne401C->credit)->toBe(100.00);
    expect((int) $ligne401C->tiers_id)->toBe((int) $tiers->id);

    // 401 D total 100, avec tiers
    $ligne401D = $transaction->lignes->where('compte_id', $compte401->id)->firstWhere('debit', '>', 0);
    expect((float) $ligne401D->debit)->toBe(100.00);
    expect((int) $ligne401D->tiers_id)->toBe((int) $tiers->id);

    // 512 C total 100, sans tiers
    $ligneTreso = $transaction->lignes->firstWhere('compte_id', $compteTreso->id);
    expect((float) $ligneTreso->credit)->toBe(100.00);
    expect($ligneTreso->tiers_id)->toBeNull();

    // Auto-lettrage interne des 2 lignes 401
    $codes = collect([$ligne401C, $ligne401D])->pluck('lettrage_code')->filter()->unique();
    expect($codes)->toHaveCount(1, 'Les 2 lignes 401 doivent partager un code de lettrage');

    // Équilibre : ∑D = ∑C = 200
    $totalDebit = $transaction->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $transaction->lignes->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe(200.00);
    expect($totalCredit)->toBe(200.00);

    // Solde ouvert 401 du tiers = 0 (lettré)
    $solde401 = TransactionLigne::where('compte_id', $compte401->id)
        ->where('tiers_id', $tiers->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $solde401)->toBe(0.00, 'Solde ouvert 401 = 0 après dépense comptant');
});

// ---------------------------------------------------------------------------
// Cas 12 : assertPasDeTiersSurClasse5 — aucune ligne classe 5 ne porte de tiers
// ---------------------------------------------------------------------------
test('pourDepenseComptant : aucune ligne classe 5 ne porte de tiers (FEC-conformité)', function () {
    $tiers = tiersCourantD();
    $compteCharge = compte607('N');
    $compteTreso = compte512DEP('FEC');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourDepenseComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteCharge, 'montant' => 80.00]],
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
