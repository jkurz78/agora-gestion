<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Transaction;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ReglementOperationService;

require_once __DIR__.'/EcritureGeneratorJournalTest.php';

beforeEach(function () {
    SystemeSeeder::seed();
});

it('pourDepotRapprochement crée un dépôt 512X/5112 lettré pour une ligne 5112 source', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $t1 = creerCreanceJrn(80.00);
    $t1->update(['compte_id' => $compteBancaire->id, 'mode_paiement' => ModePaiement::Cheque->value]);
    app(ReglementOperationService::class)->encaisserSiNonEncaisse($t1->fresh());
    $t2 = app(ReglementOperationService::class)->trouverEncaissementT2($t1->fresh());
    $compte5112 = compteSystemeJrn('5112');
    $ligne5112 = $t2->lignes->firstWhere('compte_id', $compte5112->id);

    $depot = app(EcritureGenerator::class)->pourDepotRapprochement(
        ligne5112Source: $ligne5112,
        compteCible512: $compte512,
        mode: ModePaiement::Cheque,
        date: new DateTimeImmutable('2026-05-28'),
        libelle: 'Dépôt rapprochement chèque',
    );

    expect($depot->journal->value)->toBe('banque');
    expect($depot->compte_id)->toBeNull();
    $ligne512Depot = $depot->lignes->firstWhere('compte_id', $compte512->id);
    expect((float) $ligne512Depot->debit)->toBe(80.00);
    expect($ligne5112->fresh()->lettrage_code)->not->toBeNull();
});
