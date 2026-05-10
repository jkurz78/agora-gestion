<?php

declare(strict_types=1);

use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\AdhesionService;

// Régression : un palier HelloAsso à 0€ ("Cotisation offerte") n'a pas de
// helloasso_payment_id côté API HA. La résolution prio 1 ne doit PAS
// dépendre de helloasso_payment_id, sinon ces cas tombent en prio 2 (formule
// manuelle active sur la sous-cat) avec un mauvais snapshot fiscal/mode.
it('résolveFormule prio 1 HelloAsso même sans helloasso_payment_id (palier HA à 0€)', function (): void {
    $service = app(AdhesionService::class);

    $sc = SousCategorie::factory()->pourCotisations()->create();
    $tiers = Tiers::factory()->create([
        'helloasso_nom' => 'SAND',
        'helloasso_prenom' => 'Georges',
        'est_helloasso' => true,
    ]);

    // Formule manuelle active sur la sous-cat (le piège)
    $formuleManuelle = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'nom' => 'Cotisation annuelle',
        'mode' => 'exercice',
        'deductible_fiscal' => true,
        'actif' => true,
        'est_helloasso' => false,
    ]);

    // Formule HelloAsso pour le palier "Cotisation offerte"
    $formuleHA = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $sc->id,
        'nom' => 'Un an glissant — Cotisation offerte',
        'mode' => 'duree',
        'duree_mois' => 12,
        'montant_par_defaut' => 0,
        'deductible_fiscal' => false,
        'actif' => true,
        'est_helloasso' => true,
        'helloasso_form_slug' => 'un-an-glissant',
        'helloasso_tier_id' => 18597,
    ]);

    // Transaction HelloAsso simulée : form_slug posé, payment_id NULL (palier 0€)
    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-10-15',
        'helloasso_form_slug' => 'un-an-glissant',
        'helloasso_payment_id' => null, // ← le cas du palier offert
    ]);
    TransactionLigne::where('transaction_id', $tx->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'helloasso_tier_id' => 18597,
        'montant' => 0,
    ]);

    $adhesion = $service->creerDepuisTransaction($tx);

    expect($adhesion)->not->toBeNull();
    // L'adhésion doit pointer sur la formule HelloAsso #7, PAS sur la manuelle #1
    expect($adhesion->formule_adhesion_id)->toBe($formuleHA->id);
    expect($adhesion->mode)->toBe('duree');
    expect($adhesion->deductible_fiscal)->toBeFalse();
    expect($adhesion->label_formule)->toBe('Un an glissant — Cotisation offerte');

    // Vérification anti-régression : la formule manuelle existe toujours mais n'a pas été choisie
    expect($formuleManuelle->fresh())->not->toBeNull();
});
