<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\TransactionConverter;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Tâche 8 : TransactionConverter — garde du net nul et saut de la T2.
//
// Une commande HelloAsso entièrement couverte par un code de remise produit
// une transaction à montant_total = 0 dont les ventilations se COMPENSENT
// (706 crédit / 709A débit, même montant). Ce n'est pas un artefact — c'est
// le cas normal d'une gratuité intégrale — et elle doit être comptabilisée :
// le 411 est soldé par la paire lettrée que pose EcritureGenerator (Tâche 7),
// et la T2 (encaissement) doit être sautée puisqu'il n'y a rien à encaisser.
//
// À distinguer du cas légitimement skippé : une transaction SANS aucune
// ligne de ventilation valide (le vrai artefact que la garde retirée
// protégeait réellement).
// ---------------------------------------------------------------------------

/**
 * Compte bancaire + 512X associé, nécessaire pour que CompteTresorerieResolver
 * résolve un compte de trésorerie sur le mode de paiement 'cb' (même si, pour
 * la gratuité intégrale, la T2 qui l'utiliserait est finalement sautée).
 */
function compteBancaireGratuite(): CompteBancaire
{
    $compteBancaire = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
        'iban' => 'FR7612345000098765432109876',
        'actif_recettes_depenses' => true,
    ]);

    BancairesSeeder::seed();

    return $compteBancaire;
}

function compteVentilationGratuite(string $numero, string $intitule): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numero,
        'intitule' => $intitule,
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

/**
 * Transaction Recette à 0 €, ventilations qui se compensent (706 crédit 50 /
 * 709A débit 50) — le cas d'une commande HelloAsso entièrement gratuite.
 *
 * Les lignes arrivent déjà enrichies (compte-first, comme le pipeline
 * HelloAsso DC-10a) : compte_id + debit/credit posés dès la création,
 * TransactionConverter se contente de les lire.
 */
function txGratuiteCompensee(): Transaction
{
    $compteBancaire = compteBancaireGratuite();
    $tiers = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);

    $tx = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'tiers_id' => $tiers->id,
        'compte_id' => $compteBancaire->id,
        'date' => now()->toDateString(),
        'libelle' => 'Adhésion offerte par code promo',
        'montant_total' => '0.00',
        'mode_paiement' => ModePaiement::Cb->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'equilibree' => false,
    ]);

    $compteProduit = compteVentilationGratuite('706g', 'Cotisations membres');
    $compteGratuite = compteVentilationGratuite('709Ag', 'Gratuités accordées');

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteProduit->id,
        'montant' => 50.00,
        'debit' => 0.00,
        'credit' => 50.00,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteGratuite->id,
        'montant' => 50.00,
        'debit' => 50.00,
        'credit' => 0.00,
    ]);

    return $tx;
}

beforeEach(function () {
    SystemeSeeder::seed();
});

test('une transaction dont les ventilations se compensent est convertie', function () {
    $tx = txGratuiteCompensee();

    $resultat = app(TransactionConverter::class)->convertir($tx);

    expect($resultat)->toBeTrue()
        ->and($tx->fresh()->equilibree)->toBeTrue();
});

test('aucune T2 n\'est générée quand le net encaissable est nul', function () {
    $tx = txGratuiteCompensee();

    $nbTransactionsAvant = Transaction::count();

    app(TransactionConverter::class)->convertir($tx);

    expect(Transaction::count())->toBe(
        $nbTransactionsAvant,
        'aucune T2 séparée ne doit être créée pour une gratuité intégrale'
    );

    $ligneClasse5Existe = TransactionLigne::whereHas('compte', fn ($q) => $q->where('classe', 5))->exists();

    expect($ligneClasse5Existe)->toBeFalse(
        'aucune ligne de trésorerie (classe 5) ne doit exister — rien n\'a été encaissé'
    );
});

test('le statut dérivé vaut Recu — tiers lettré sans trésorerie, dette soldée', function () {
    $tx = txGratuiteCompensee();

    app(TransactionConverter::class)->convertir($tx);

    $statut = app(EtatReglementResolver::class)->resolve($tx->fresh());

    expect($statut)->toBe(StatutReglement::Recu);
});

test('une transaction sans aucune ligne de ventilation est toujours skippée', function () {
    $compteBancaire = compteBancaireGratuite();
    $tiers = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);

    $tx = Transaction::create([
        'association_id' => TenantContext::currentId(),
        'type' => TypeTransaction::Recette,
        'tiers_id' => $tiers->id,
        'compte_id' => $compteBancaire->id,
        'date' => now()->toDateString(),
        'libelle' => 'Transaction orpheline, sans ventilation',
        'montant_total' => '0.00',
        'mode_paiement' => ModePaiement::Cb->value,
        'statut_reglement' => StatutReglement::Recu->value,
        'equilibree' => false,
    ]);

    $resultat = app(TransactionConverter::class)->convertir($tx);

    expect($resultat)->toBeFalse()
        ->and($tx->fresh()->equilibree)->toBeFalsy();
});
