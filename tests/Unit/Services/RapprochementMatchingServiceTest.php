<?php

declare(strict_types=1);

use App\DTOs\ReleveOcrMouvement;
use App\Services\RapprochementMatchingService;

it('associe un match unique par montant exact', function (): void {
    $mouvement = new ReleveOcrMouvement(
        date: '2026-01-10',
        libelle: 'Virement',
        montant: -85.00,
    );

    $transactions = collect([
        ['id' => 1, 'type' => 'depense', 'date' => '2026-01-10', 'libelle' => 'Virement', 'montant_signe' => -85.00],
    ]);

    $result = app(RapprochementMatchingService::class)->matcher([$mouvement], $transactions);

    expect($result->propositions)->toHaveCount(1)
        ->and($result->nonApparies)->toHaveCount(0)
        ->and($result->propositions[0]->transaction_id)->toBe(1)
        ->and($result->propositions[0]->score)->toBe(20);
});

it('desambiguise par proximite de date quand le montant est identique', function (): void {
    $mouvement = new ReleveOcrMouvement(
        date: '2026-01-15',
        libelle: null,
        montant: -100.00,
    );

    $transactions = collect([
        ['id' => 1, 'type' => 'depense', 'date' => '2026-01-15', 'libelle' => null, 'montant_signe' => -100.00],
        ['id' => 2, 'type' => 'depense', 'date' => '2026-01-25', 'libelle' => null, 'montant_signe' => -100.00],
    ]);

    $result = app(RapprochementMatchingService::class)->matcher([$mouvement], $transactions);

    expect($result->propositions)->toHaveCount(1)
        ->and($result->nonApparies)->toHaveCount(0)
        ->and($result->propositions[0]->transaction_id)->toBe(1);
});

it('desambiguise par similarite de libelle quand montant et date sont identiques', function (): void {
    $mouvement = new ReleveOcrMouvement(
        date: '2026-01-15',
        libelle: 'Cotisation adhérent',
        montant: 50.00,
    );

    $transactions = collect([
        ['id' => 1, 'type' => 'recette', 'date' => '2026-01-15', 'libelle' => 'Cotisation adhérent Dupont', 'montant_signe' => 50.00],
        ['id' => 2, 'type' => 'recette', 'date' => '2026-01-15', 'libelle' => 'Don mensuel', 'montant_signe' => 50.00],
    ]);

    $result = app(RapprochementMatchingService::class)->matcher([$mouvement], $transactions);

    expect($result->propositions)->toHaveCount(1)
        ->and($result->nonApparies)->toHaveCount(0)
        ->and($result->propositions[0]->transaction_id)->toBe(1);
});

it('laisse non apparie un mouvement dont le montant est introuvable', function (): void {
    $mouvement = new ReleveOcrMouvement(
        date: '2026-01-10',
        libelle: 'Test',
        montant: -999.99,
    );

    $transactions = collect([
        ['id' => 1, 'type' => 'depense', 'date' => '2026-01-10', 'libelle' => 'Autre', 'montant_signe' => -50.00],
        ['id' => 2, 'type' => 'depense', 'date' => '2026-01-11', 'libelle' => 'Autre encore', 'montant_signe' => -100.00],
    ]);

    $result = app(RapprochementMatchingService::class)->matcher([$mouvement], $transactions);

    expect($result->propositions)->toHaveCount(0)
        ->and($result->nonApparies)->toHaveCount(1);
});

it('laisse non apparie le second mouvement quand le pool de candidats est epuise', function (): void {
    $mouvement1 = new ReleveOcrMouvement(
        date: '2026-01-10',
        libelle: 'A',
        montant: -50.00,
    );
    $mouvement2 = new ReleveOcrMouvement(
        date: '2026-01-11',
        libelle: 'B',
        montant: -50.00,
    );

    $transactions = collect([
        ['id' => 1, 'type' => 'depense', 'date' => '2026-01-10', 'libelle' => 'A', 'montant_signe' => -50.00],
    ]);

    $result = app(RapprochementMatchingService::class)->matcher([$mouvement1, $mouvement2], $transactions);

    expect($result->propositions)->toHaveCount(1)
        ->and($result->propositions[0]->transaction_id)->toBe(1)
        ->and($result->nonApparies)->toHaveCount(1)
        ->and($result->nonApparies[0]->montant)->toBe(-50.00);
});

it('laisse non apparie un mouvement dont le meilleur score reste sous le seuil minimum', function (): void {
    $mouvement = new ReleveOcrMouvement(
        date: '2026-01-01',
        libelle: 'XYZ123',
        montant: -100.00,
    );

    $transactions = collect([
        ['id' => 1, 'type' => 'depense', 'date' => '2026-06-01', 'libelle' => 'Virement carte bancaire', 'montant_signe' => -100.00],
        ['id' => 2, 'type' => 'depense', 'date' => '2026-06-01', 'libelle' => 'Prelevement EDF', 'montant_signe' => -100.00],
    ]);

    $result = app(RapprochementMatchingService::class)->matcher([$mouvement], $transactions);

    expect($result->propositions)->toHaveCount(0)
        ->and($result->nonApparies)->toHaveCount(1);
});
