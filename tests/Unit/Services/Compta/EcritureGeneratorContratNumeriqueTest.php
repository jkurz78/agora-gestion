<?php

declare(strict_types=1);

use App\Enums\SensVentilation;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Contrat numérique de pourRecetteACredit (Tâche 6) :
//   - chaque ventilation porte un `sens` explicite (SensVentilation)
//   - montant de chaque ventilation strictement positif
//   - Σcrédit > 0
//   - Σdébit ≤ Σcrédit
//   - net (Σcrédit − Σdébit) ≥ 0
//   - tous les invariants sont vérifiés AVANT toute écriture en base
//
// Les tests 5 et 6 dépendent de la Tâche 7 (forme des lignes 411 quand une
// ventilation au débit — gratuité 709A — est présente) : voir commentaires.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Helpers locaux (suffixés ContratNum pour éviter toute collision de nom de
// fonction globale avec les autres fichiers de tests Pest de ce répertoire)
// ---------------------------------------------------------------------------

function tiersContratNum(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

function compte706ContratNum(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706cn'.$suffix,
        'intitule' => 'Formations '.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

function compte709AContratNum(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '709Acn'.$suffix,
        'intitule' => 'Gratuités accordées '.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

beforeEach(function () {
    SystemeSeeder::seed();
});

// ---------------------------------------------------------------------------
// Invariant 1 : sens absent → InvalidArgumentException, aucune écriture
// ---------------------------------------------------------------------------
test('pourRecetteACredit lève InvalidArgumentException si sens absent, aucune écriture créée', function () {
    $tiers = tiersContratNum();
    $compteProduit = compte706ContratNum('1');

    $transactionsBefore = Transaction::count();
    $lignesBefore = TransactionLigne::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        // Pas de clé 'sens' — c'est précisément ce que ce test vérifie.
        ventilations: [['compte' => $compteProduit, 'montant' => 50.00]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
    expect(TransactionLigne::count())->toBe($lignesBefore);
});

// ---------------------------------------------------------------------------
// Invariant 2 : montant nul ou négatif → exception, aucune écriture (2 valeurs)
// ---------------------------------------------------------------------------
test('pourRecetteACredit lève InvalidArgumentException si montant de ventilation ≤ 0, aucune écriture créée', function () {
    $tiers = tiersContratNum();
    $compteProduit = compte706ContratNum('2');

    $transactionsBefore = Transaction::count();
    $lignesBefore = TransactionLigne::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => 0.00, 'sens' => SensVentilation::Credit]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => -50.00, 'sens' => SensVentilation::Credit]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
    expect(TransactionLigne::count())->toBe($lignesBefore);
});

// ---------------------------------------------------------------------------
// Invariant 3 : recette sans aucun produit (une seule ventilation au débit)
// → exception, aucune écriture
// ---------------------------------------------------------------------------
test('pourRecetteACredit lève InvalidArgumentException si aucune ventilation au crédit, aucune écriture créée', function () {
    $tiers = tiersContratNum();
    $compteGratuite = compte709AContratNum('3');

    $transactionsBefore = Transaction::count();
    $lignesBefore = TransactionLigne::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteGratuite, 'montant' => 50.00, 'sens' => SensVentilation::Debit]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
    expect(TransactionLigne::count())->toBe($lignesBefore);
});

// ---------------------------------------------------------------------------
// Invariant 4 : remise supérieure au produit (crédit 50, débit 80)
// → exception, aucune écriture
// ---------------------------------------------------------------------------
test('pourRecetteACredit lève InvalidArgumentException si Σdébit > Σcrédit, aucune écriture créée', function () {
    $tiers = tiersContratNum();
    $compteProduit = compte706ContratNum('4');
    $compteGratuite = compte709AContratNum('4');

    $transactionsBefore = Transaction::count();
    $lignesBefore = TransactionLigne::count();

    $generator = app(EcritureGenerator::class);

    expect(fn () => $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compteProduit, 'montant' => 50.00, 'sens' => SensVentilation::Credit],
            ['compte' => $compteGratuite, 'montant' => 80.00, 'sens' => SensVentilation::Debit],
        ],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    ))->toThrow(InvalidArgumentException::class);

    expect(Transaction::count())->toBe($transactionsBefore);
    expect(TransactionLigne::count())->toBe($lignesBefore);
});

// ---------------------------------------------------------------------------
// Invariant 5 : exactitude en centimes — 33,33 / 33,33 / 33,34 → 100.00 exact
// Dépend de la Tâche 7 pour la forme définitive des lignes 411, mais ce cas
// (3 ventilations toutes au crédit, pas de débit) n'exerce pas la forme à
// deux lignes 411 — seul le calcul en centimes de cette Tâche 6 est en jeu ici.
// ---------------------------------------------------------------------------
test('pourRecetteACredit calcule le total en centimes exacts (pas de dérive flottante)', function () {
    $tiers = tiersContratNum();
    $compteProduit = compte706ContratNum('5');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compteProduit, 'montant' => 33.33, 'sens' => SensVentilation::Credit],
            ['compte' => $compteProduit, 'montant' => 33.33, 'sens' => SensVentilation::Credit],
            ['compte' => $compteProduit, 'montant' => 33.34, 'sens' => SensVentilation::Credit],
        ],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    expect((float) $transaction->montant_total)->toBe(100.00);

    $compte411 = Compte::ofNumeroSysteme('411');
    $ligne411 = $transaction->lignes->firstWhere('compte_id', $compte411->id);

    expect((float) $ligne411->debit)->toBe(100.00);
});

// ---------------------------------------------------------------------------
// Invariant 6 : montant_total du header au NET (crédit 50 + débit 20 → 30.00),
// jamais au brut, quand pourRecetteACredit crée lui-même la Transaction.
// Dépend de la Tâche 7 : tant que la forme des lignes 411/7xx en présence
// d'une ventilation au débit n'est pas décidée, les lignes 7xx sont encore
// toutes créées au crédit (y compris la ventilation au débit), ce qui casse
// l'équilibre D/C et fait échouer cet appel avec EcritureNonEquilibreeException
// au lieu de produire la Transaction attendue.
// ---------------------------------------------------------------------------
test('pourRecetteACredit porte le net (Σcrédit − Σdébit) sur montant_total du header', function () {
    $tiers = tiersContratNum();
    $compteProduit = compte706ContratNum('6');
    $compteGratuite = compte709AContratNum('6');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compteProduit, 'montant' => 50.00, 'sens' => SensVentilation::Credit],
            ['compte' => $compteGratuite, 'montant' => 20.00, 'sens' => SensVentilation::Debit],
        ],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    expect((float) $transaction->montant_total)->toBe(30.00);
});
