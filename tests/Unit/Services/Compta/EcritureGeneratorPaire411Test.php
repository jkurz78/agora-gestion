<?php

declare(strict_types=1);

use App\Enums\SensVentilation;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Tâche 7 : forme des lignes 411 quand une ventilation au débit (gratuité)
// est présente.
//
//   net > 0 → une seule ligne 411 D au net (créance ouverte, inchangé)
//   net = 0 → une paire 411 D / 411 C lettrée, la créditrice rattachée à la
//             débitrice via poste_tiers_parent_id — la place offerte laisse
//             une trace sur le compte du tiers sans jamais rouvrir de poste.
//
// Voir EcritureGenerator::pourRecetteACredit() pour le pourquoi complet.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Helpers locaux (suffixés Paire411 pour éviter toute collision de nom de
// fonction globale avec les autres fichiers de tests Pest de ce répertoire)
// ---------------------------------------------------------------------------

function tiersPaire411(): Tiers
{
    return Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
}

function compte706Paire411(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '706p'.$suffix,
        'intitule' => 'Formations '.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

function compte709APaire411(string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '709Ap'.$suffix,
        'intitule' => 'Gratuités accordées '.$suffix,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

function compte411Paire411(): Compte
{
    return Compte::where('numero_pcg', '411')
        ->where('association_id', TenantContext::currentId())
        ->where('est_systeme', true)
        ->firstOrFail();
}

beforeEach(function () {
    SystemeSeeder::seed();
});

// ---------------------------------------------------------------------------
// Cas 1 : Remise partielle (crédit 50, débit 20) → net 30 > 0
// Une seule ligne 411, non lettrée, sans rattachement — créance ouverte.
// ---------------------------------------------------------------------------
test('pourRecetteACredit remise partielle : une seule ligne 411 au net, non lettrée', function () {
    $tiers = tiersPaire411();
    $compteProduit = compte706Paire411('1');
    $compteGratuite = compte709APaire411('1');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compteProduit, 'montant' => 50.00, 'sens' => SensVentilation::Credit],
            ['compte' => $compteGratuite, 'montant' => 20.00, 'sens' => SensVentilation::Debit],
        ],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    $compte411 = compte411Paire411();

    $lignes411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->get();

    expect($lignes411)->toHaveCount(1);

    $ligne411 = $lignes411->first();
    expect((float) $ligne411->debit)->toBe(30.00)
        ->and((float) $ligne411->credit)->toBe(0.00)
        ->and($ligne411->lettrage_code)->toBeNull('la créance reste ouverte : elle attend encore 30,00')
        ->and($ligne411->poste_tiers_parent_id)->toBeNull();

    // Ventilations : 706 au crédit pour 50, 709A au débit pour 20 (sens respecté).
    $ligneProduit = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compteProduit->id)->sole();
    expect((float) $ligneProduit->credit)->toBe(50.00)
        ->and((float) $ligneProduit->debit)->toBe(0.00);

    $ligneGratuite = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compteGratuite->id)->sole();
    expect((float) $ligneGratuite->debit)->toBe(20.00)
        ->and((float) $ligneGratuite->credit)->toBe(0.00);
});

// ---------------------------------------------------------------------------
// Cas 2 : Remise totale (crédit 50, débit 50) → net 0
// Paire 411 D/C lettrée au même code, rattachement poste_tiers_parent_id sur
// la créditrice, montant_total du header au net (0.00).
// ---------------------------------------------------------------------------
test('pourRecetteACredit remise totale : paire 411 lettrée et rattachée, montant_total à 0', function () {
    $tiers = tiersPaire411();
    $compteProduit = compte706Paire411('2');
    $compteGratuite = compte709APaire411('2');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compteProduit, 'montant' => 50.00, 'sens' => SensVentilation::Credit],
            ['compte' => $compteGratuite, 'montant' => 50.00, 'sens' => SensVentilation::Debit],
        ],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    $compte411 = compte411Paire411();

    $lignes411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->orderBy('id')
        ->get();

    expect($lignes411)->toHaveCount(2);

    $ligneDebitrice = $lignes411->firstWhere(fn (TransactionLigne $l): bool => (float) $l->debit > 0);
    $ligneCreditrice = $lignes411->firstWhere(fn (TransactionLigne $l): bool => (float) $l->credit > 0);

    expect($ligneDebitrice)->not->toBeNull()
        ->and($ligneCreditrice)->not->toBeNull();

    expect((float) $ligneDebitrice->debit)->toBe(50.00)
        ->and((float) $ligneDebitrice->credit)->toBe(0.00)
        ->and((float) $ligneCreditrice->credit)->toBe(50.00)
        ->and((float) $ligneCreditrice->debit)->toBe(0.00);

    // Même code de lettrage sur les deux lignes.
    expect($ligneDebitrice->lettrage_code)->not->toBeNull()
        ->and($ligneCreditrice->lettrage_code)->not->toBeNull()
        ->and($ligneCreditrice->lettrage_code)->toBe($ligneDebitrice->lettrage_code);

    // Rattachement : la créditrice pointe vers la débitrice, jamais l'inverse.
    expect((int) $ligneCreditrice->poste_tiers_parent_id)->toBe((int) $ligneDebitrice->id)
        ->and($ligneDebitrice->poste_tiers_parent_id)->toBeNull();

    expect((float) $transaction->montant_total)->toBe(0.00);
});

// ---------------------------------------------------------------------------
// Cas 3 : Aucun règlement fantôme sur une remise totale.
// C'est le test qui protège contre le paiement fantôme décrit dans la tâche :
// sans rattachement, PostesTiersOuvertsService::reglements() prendrait la
// ligne créditrice pour un règlement de 50 € porté par la T1 elle-même.
// ---------------------------------------------------------------------------
test('pourRecetteACredit remise totale : aucun règlement fantôme, solde actif nul', function () {
    $tiers = tiersPaire411();
    $compteProduit = compte706Paire411('3');
    $compteGratuite = compte709APaire411('3');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compteProduit, 'montant' => 50.00, 'sens' => SensVentilation::Credit],
            ['compte' => $compteGratuite, 'montant' => 50.00, 'sens' => SensVentilation::Debit],
        ],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    $service = app(PostesTiersOuvertsService::class);

    expect($service->reglements($transaction->fresh()))->toBeEmpty(
        'la paire lettrée ne doit jamais apparaître comme un règlement de la T1'
    );

    expect($service->soldeActifPourTransaction($transaction->fresh()))->toBe(0);
});

// ---------------------------------------------------------------------------
// Cas 4 : Commande sans remise (crédit seul, pas de ventilation au débit)
// Non-régression : comportement rigoureusement identique à avant la tâche.
// ---------------------------------------------------------------------------
test('pourRecetteACredit sans remise : une seule ligne 411 au total, non lettrée', function () {
    $tiers = tiersPaire411();
    $compteProduit = compte706Paire411('4');

    $generator = app(EcritureGenerator::class);

    $transaction = $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [
            ['compte' => $compteProduit, 'montant' => 50.00, 'sens' => SensVentilation::Credit],
        ],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
    );

    $compte411 = compte411Paire411();

    $lignes411 = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('compte_id', $compte411->id)
        ->get();

    expect($lignes411)->toHaveCount(1);

    $ligne411 = $lignes411->first();
    expect((float) $ligne411->debit)->toBe(50.00)
        ->and((float) $ligne411->credit)->toBe(0.00)
        ->and($ligne411->lettrage_code)->toBeNull();
});
