<?php

declare(strict_types=1);

use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Transaction;
use App\Services\Immobilisation\PlanAmortissementCalculator;

/**
 * C2 (audit) — construirePlan() (ImmobilisationShow) et plan()
 * (ImmobilisationPdfController) existaient en double, à une ligne près déjà
 * divergente. Ce fichier verrouille le point de construction unique,
 * PlanAmortissementCalculator::plan(), indépendamment de tout composant
 * Livewire ou moteur PDF. La parité écran/PDF elle-même est prouvée par
 * tests/Feature/Immobilisation/PlanEcranPdfIdentiqueTest.php.
 */
it('construit le plan complet d’un bien neuf, sans aucune dotation comptabilisée', function (): void {
    $immo = Immobilisation::factory()->create([
        'date_mise_en_service' => '2026-09-12',
        'duree_mois' => 60,
        'montant_acquisition' => '3000.00',
    ])->fresh(['dotations']);

    $plan = app(PlanAmortissementCalculator::class)->plan($immo);

    expect($plan)->toHaveCount(5)
        ->and($plan[0]->exercice)->toBe(2026)
        ->and($plan[0]->dotationCentimes)->toBe(60000)
        ->and($plan[0]->comptabilisee)->toBeFalse()
        ->and($plan[0]->transactionId)->toBeNull()
        ->and($plan[4]->exercice)->toBe(2030)
        ->and($plan[4]->cumulCentimes)->toBe(300000)
        ->and($plan[4]->valeurNetteCentimes)->toBe(0);
});

it('distingue les exercices comptabilisés des projections sur un plan partiellement doté', function (): void {
    $immo = Immobilisation::factory()->create([
        'date_mise_en_service' => '2026-09-12',
        'duree_mois' => 60,
        'montant_acquisition' => '3000.00',
    ]);

    $transactionDotation = Transaction::factory()->create();
    ImmobilisationDotation::create([
        'immobilisation_id' => $immo->id,
        'exercice' => 2026,
        'montant' => '600.00',
        'transaction_id' => $transactionDotation->id,
    ]);

    $plan = app(PlanAmortissementCalculator::class)->plan($immo->fresh(['dotations']));

    expect($plan[0]->comptabilisee)->toBeTrue()
        ->and($plan[0]->dotationCentimes)->toBe(60000)
        ->and($plan[0]->transactionId)->toBe((int) $transactionDotation->id)
        ->and($plan[1]->comptabilisee)->toBeFalse()
        ->and($plan[1]->transactionId)->toBeNull();
});

/**
 * Purement date-driven : le plan complet se construit même quand la mise en
 * service est postérieure au « now » gelé par tests/Pest.php — rien dans
 * plan() ne dépend de l'horloge.
 */
it('construit un plan complet pour un bien pas encore mis en service', function (): void {
    $immo = Immobilisation::factory()->create([
        'date_mise_en_service' => '2028-03-01',
        'duree_mois' => 60,
        'montant_acquisition' => '3000.00',
    ])->fresh(['dotations']);

    $plan = app(PlanAmortissementCalculator::class)->plan($immo);

    // Le premier exercice n'apporte que 6 mois (au lieu de 12) : il faut un
    // exercice de plus qu'un plan démarrant en septembre pour solder le bien.
    expect($plan)->toHaveCount(6)
        ->and($plan[0]->exercice)->toBe(2027) // exercice couvrant le 01/03/2028
        ->and($plan[0]->moisEcoules)->toBe(6) // mars → août 2028 inclus
        ->and($plan[0]->comptabilisee)->toBeFalse()
        ->and($plan[5]->valeurNetteCentimes)->toBe(0);
});

it('gère une durée non multiple de douze dans le plan complet', function (): void {
    $immo = Immobilisation::factory()->create([
        'date_mise_en_service' => '2026-09-01',
        'duree_mois' => 30,
        'montant_acquisition' => '3000.00',
    ])->fresh(['dotations']);

    $plan = app(PlanAmortissementCalculator::class)->plan($immo);

    expect($plan)->toHaveCount(3)
        ->and($plan[0]->cumulCentimes)->toBe(120000)
        ->and($plan[1]->cumulCentimes)->toBe(240000)
        ->and($plan[2]->cumulCentimes)->toBe(300000)
        ->and($plan[2]->valeurNetteCentimes)->toBe(0);
});
