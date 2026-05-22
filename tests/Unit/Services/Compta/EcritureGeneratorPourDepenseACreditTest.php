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

function compteSystemeDAC(string $numeroPcg): Compte
{
    return Compte::where('numero_pcg', $numeroPcg)
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

function compte607DAC(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '607dac'.$suffix,
        'intitule' => 'Achats matières '.$suffix,
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

function tiersDAC(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

// ---------------------------------------------------------------------------
// beforeEach : seed des comptes système
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();
});

// ---------------------------------------------------------------------------
// Cas 1 : Dette fournisseur normale → T1 : 607 D X (sans tiers) / 401 C X (avec tiers)
// ---------------------------------------------------------------------------
test('pourDepenseACredit crée T1 607 D X sans tiers / 401 C X avec tiers', function () {
    $tiers = tiersDAC();
    $compteCharge = compte607DAC('A');
    $compte401 = compteSystemeDAC('401');

    $generator = app(EcritureGenerator::class);

    $t1 = $generator->pourDepenseACredit(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 250.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
        libelle: 'Facture fournisseur test',
    );

    expect($t1)->toBeInstanceOf(Transaction::class);
    expect($t1->lignes)->toHaveCount(2);

    // Ligne 607 : débit, sans tiers
    $ligneCharge = $t1->lignes->firstWhere('compte_id', $compteCharge->id);
    expect($ligneCharge)->not->toBeNull('Ligne 607 attendue dans T1');
    expect((float) $ligneCharge->debit)->toBe(250.00);
    expect((float) $ligneCharge->credit)->toBe(0.00);
    expect($ligneCharge->tiers_id)->toBeNull('Ligne charge sans tiers');

    // Ligne 401 : crédit, avec tiers
    $ligne401 = $t1->lignes->firstWhere('compte_id', $compte401->id);
    expect($ligne401)->not->toBeNull('Ligne 401 attendue dans T1');
    expect((float) $ligne401->debit)->toBe(0.00);
    expect((float) $ligne401->credit)->toBe(250.00);
    expect((int) $ligne401->tiers_id)->toBe((int) $tiers->id, 'Ligne 401 doit porter le tiers fournisseur');
});

// ---------------------------------------------------------------------------
// Cas 2 : T1 équilibrée, type=Depense, equilibree=TRUE, type_ecriture='normale', mode_paiement=null
// ---------------------------------------------------------------------------
test('pourDepenseACredit produit T1 equilibree=TRUE, type=Depense, type_ecriture=normale, mode_paiement=null', function () {
    $tiers = tiersDAC();
    $compteCharge = compte607DAC('B');

    $generator = app(EcritureGenerator::class);

    $t1 = $generator->pourDepenseACredit(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 100.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    expect($t1->equilibree)->toBeTrue();
    expect($t1->type)->toBe(TypeTransaction::Depense);
    expect($t1->type_ecriture)->toBe('normale');
    expect($t1->mode_paiement)->toBeNull();

    $totalDebit = $t1->lignes->sum(fn ($l) => (float) $l->debit);
    $totalCredit = $t1->lignes->sum(fn ($l) => (float) $l->credit);
    expect($totalDebit)->toBe(100.00);
    expect($totalCredit)->toBe(100.00);
});

// ---------------------------------------------------------------------------
// Cas 3 : Solde ouvert 401 du tiers = -X après création (créditeur)
// ---------------------------------------------------------------------------
test('pourDepenseACredit : solde ouvert 401 du tiers = −montant après création', function () {
    $tiers = tiersDAC();
    $compteCharge = compte607DAC('C');
    $compte401 = compteSystemeDAC('401');
    $montant = 350.00;

    $generator = app(EcritureGenerator::class);
    $generator->pourDepenseACredit(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: $montant,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    // Solde 401 du tiers = SUM(debit) - SUM(credit) = 0 - 350 = -350 (créditeur = dette)
    $solde = TransactionLigne::where('compte_id', $compte401->id)
        ->where('tiers_id', $tiers->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');

    expect((float) $solde)->toBe(-350.00);
});

// ---------------------------------------------------------------------------
// Cas 4 : Compte charge classe ≠ 6 → CompteIncorrectException
// ---------------------------------------------------------------------------
test('pourDepenseACredit lève CompteIncorrectException si compteCharge ∉ classe 6', function () {
    $tiers = tiersDAC();

    // Compte classe 7 (produit) — mauvaise classe
    $compteProduit = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706dac_err',
        'intitule' => 'Cotisations (erreur)',
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourDepenseACredit(
        tiers: $tiers,
        compteCharge: $compteProduit,
        montant: 100.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(CompteIncorrectException::class);
});

// ---------------------------------------------------------------------------
// Cas 5 : Montant ≤ 0 → \InvalidArgumentException
// ---------------------------------------------------------------------------
test('pourDepenseACredit lève InvalidArgumentException si montant ≤ 0', function () {
    $tiers = tiersDAC();
    $compteCharge = compte607DAC('E');

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourDepenseACredit(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: 0.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->pourDepenseACredit(
        tiers: $tiers,
        compteCharge: $compteCharge,
        montant: -50.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Cas 6 : Tiers cross-tenant → TenantBoundaryException, rollback
// ---------------------------------------------------------------------------
test('pourDepenseACredit lève TenantBoundaryException si tiers cross-tenant', function () {
    $associationB = Association::factory()->create();
    $tiersBTenant = Tiers::factory()->create(['association_id' => $associationB->id]);

    $compteCharge = compte607DAC('F');

    $transactionsBefore = Transaction::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourDepenseACredit(
        tiers: $tiersBTenant,
        compteCharge: $compteCharge,
        montant: 100.00,
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(TenantBoundaryException::class);

    expect(Transaction::count())->toBe($transactionsBefore, 'Aucune transaction T1 ne doit être créée');
});
