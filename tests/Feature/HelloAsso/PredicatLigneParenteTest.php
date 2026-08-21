<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\AdhesionService;
use App\Services\UsagesComptablesService;

/*
 * Tâche 5 — prédicat "ligne parente" compatible manuel + HelloAsso.
 *
 * Les sélecteurs qui identifient la ligne parente d'une commande basculent de
 * whereNull('helloasso_option_id') vers un prédicat groupé :
 *   whereNull('helloasso_item_id') OR helloasso_line_key = 'parent'
 *
 * Ces deux tests protègent les deux régressions possibles de cette bascule :
 *   1. Une transaction manuelle (helloasso_item_id ET helloasso_line_key NULL)
 *      doit continuer à être détectée — sinon toutes les adhésions et reçus
 *      fiscaux saisis à la main disparaissent.
 *   2. Une ligne de remise HelloAsso (line_key='discount', même item_id que la
 *      ligne parente) ne doit jamais être prise pour la ligne parente.
 */
it('détecte toujours la ligne cotisation d\'une adhésion saisie manuellement', function (): void {
    $compte = Compte::factory()->create();
    app(UsagesComptablesService::class)->toggleCotisation((int) $compte->id, true);

    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
    ]);

    // Les lignes auto-créées par TransactionFactory::configure() n'ont pas de
    // compte_id : elles sont neutres vis-à-vis du filtre whereHas('compte.usages'),
    // mais on les retire pour ne garder qu'une ligne de ventilation contrôlée.
    TransactionLigne::where('transaction_id', $tx->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
        // Ligne manuelle : ni helloasso_item_id ni helloasso_line_key (NULL par défaut).
    ]);

    $adhesion = app(AdhesionService::class)->creerDepuisTransaction($tx);

    expect($adhesion)->not->toBeNull();
    expect((int) $adhesion->transaction_id)->toBe((int) $tx->id);
});

it('ne retient jamais la ligne de remise HelloAsso comme ligne parente', function (): void {
    $compteCotisation = Compte::factory()->create();
    app(UsagesComptablesService::class)->toggleCotisation((int) $compteCotisation->id, true);

    // Formule manuelle portée par le compte cotisation : sert de marqueur pour
    // prouver que c'est bien la ligne parente (et non la ligne remise) qui a
    // été résolue — si la ligne remise était retenue par erreur, resolveFormule
    // interrogerait son propre compte (709A, sans formule) et renverrait null.
    $formule = FormuleAdhesion::factory()->create([
        'compte_id' => $compteCotisation->id,
    ]);

    $compteRemise = Compte::factory()->numero('709A')->create();

    $tiers = Tiers::factory()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
    ]);

    TransactionLigne::where('transaction_id', $tx->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteCotisation->id,
        'montant' => 35.00,
        'debit' => 0,
        'credit' => 35.00,
        'helloasso_item_id' => 99010,
        'helloasso_line_key' => 'parent',
    ]);

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteRemise->id,
        'montant' => 10.00,
        'debit' => 10.00,
        'credit' => 0,
        'helloasso_item_id' => 99010,
        'helloasso_line_key' => 'discount',
        'helloasso_discount_code' => 'PROMO2026',
    ]);

    $adhesion = app(AdhesionService::class)->creerDepuisTransaction($tx);

    expect($adhesion)->not->toBeNull();
    expect((int) $adhesion->transaction_id)->toBe((int) $tx->id);
    expect((int) $adhesion->formule_adhesion_id)->toBe((int) $formule->id);
});
