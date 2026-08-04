<?php

declare(strict_types=1);

use App\Models\Immobilisation;
use App\Services\Immobilisation\PlanAmortissementCalculator;

function immo(string $miseEnService, int $dureeMois, string $montant): Immobilisation
{
    return Immobilisation::factory()->make([
        'date_mise_en_service' => $miseEnService,
        'duree_mois' => $dureeMois,
        'montant_acquisition' => $montant,
    ]);
}

it('compte le mois de mise en service pour un mois entier', function (): void {
    $calc = app(PlanAmortissementCalculator::class);

    // Exercice 2026 = 01/09/2026 → 31/08/2027. Mise en service en février 2027 :
    // février à août inclus = 7 mois, que la MES soit le 12 ou le 26.
    expect($calc->moisEcoules(immo('2027-02-12', 36, '1000.00'), 2026))->toBe(7)
        ->and($calc->moisEcoules(immo('2027-02-26', 36, '1000.00'), 2026))->toBe(7);
});

it('plafonne les mois écoulés à la durée', function (): void {
    $calc = app(PlanAmortissementCalculator::class);

    expect($calc->moisEcoules(immo('2027-02-15', 36, '1000.00'), 2029))->toBe(36);
});

it('plancher les mois écoulés à zéro quand la mise en service est postérieure', function (): void {
    $calc = app(PlanAmortissementCalculator::class);

    expect($calc->moisEcoules(immo('2028-03-01', 60, '3000.00'), 2026))->toBe(0);
});

it('produit une année pleine sur un exercice complet', function (): void {
    $calc = app(PlanAmortissementCalculator::class);
    $immo = immo('2026-09-12', 60, '3000.00');

    expect($calc->cumulTheoriqueCentimes($immo, 2026))->toBe(60000)
        ->and($calc->dotationCentimes($immo, 2026, 0))->toBe(60000)
        ->and($calc->dotationCentimes($immo, 2027, 60000))->toBe(60000);
});

it('absorbe les arrondis et solde le bien à l’euro près', function (): void {
    $calc = app(PlanAmortissementCalculator::class);
    $immo = immo('2027-02-15', 36, '1000.00');

    $d2026 = $calc->dotationCentimes($immo, 2026, 0);
    $d2027 = $calc->dotationCentimes($immo, 2027, $d2026);
    $d2028 = $calc->dotationCentimes($immo, 2028, $d2026 + $d2027);
    $d2029 = $calc->dotationCentimes($immo, 2029, $d2026 + $d2027 + $d2028);

    expect($d2026)->toBe(19444)
        ->and($d2027)->toBe(33334)
        ->and($d2028)->toBe(33333)
        ->and($d2029)->toBe(13889)
        ->and($d2026 + $d2027 + $d2028 + $d2029)->toBe(100000);
});

it('gère une durée non multiple de douze', function (): void {
    $calc = app(PlanAmortissementCalculator::class);
    $immo = immo('2026-09-01', 30, '3000.00');

    // 12 mois sur 30 → 1200,00 €
    expect($calc->cumulTheoriqueCentimes($immo, 2026))->toBe(120000)
        // 24 mois sur 30 → 2400,00 €
        ->and($calc->cumulTheoriqueCentimes($immo, 2027))->toBe(240000)
        // 30 mois plafonnés → soldé
        ->and($calc->cumulTheoriqueCentimes($immo, 2028))->toBe(300000);
});

it('rattrape une durée corrigée en cours de vie sur l’exercice suivant', function (): void {
    $calc = app(PlanAmortissementCalculator::class);

    // 3 000 € sur 60 mois : 600 € comptabilisés en 2026.
    // La durée est ramenée à 30 mois → cumul théorique fin 2027 = 2 400 €.
    $immoCorrigee = immo('2026-09-01', 30, '3000.00');

    expect($calc->dotationCentimes($immoCorrigee, 2027, 60000))->toBe(180000);
});

it('ne dote rien quand le bien n’est pas encore en service', function (): void {
    $calc = app(PlanAmortissementCalculator::class);
    $immo = immo('2028-03-01', 60, '3000.00');

    expect($calc->dotationCentimes($immo, 2026, 0))->toBe(0);
});
