<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\RemiseBancaireSelection;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

require_once __DIR__.'/EcritureGeneratorJournalTest.php';

beforeEach(function () {
    SystemeSeeder::seed();
});

it('la sélection de candidats remise exclut les écritures journal=banque (T2)', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();

    // T2 journal=banque via encaissement d'une créance
    $t1Creance = creerCreanceJrn(50.00);
    app(EcritureGenerator::class)->pourEncaissementCreance(
        transactionCreance: $t1Creance,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compte512,
        datePaiement: new DateTimeImmutable('2026-05-25'),
        libelle: 'Encaissement test',
    );

    $remise = RemiseBancaire::create([
        'association_id' => TenantContext::currentId(),
        'numero' => 9002, 'date' => '2026-05-26',
        'mode_paiement' => ModePaiement::Cheque,
        'compte_cible_id' => $compteBancaire->id,
        'libelle' => 'Remise candidats test', 'saisi_par' => userIdJrn(),
    ]);

    $component = Livewire::test(RemiseBancaireSelection::class, ['remise' => $remise]);
    // allTransactions est la variable interne — mais render() expose 'transactions' à la vue
    // La base query expose les candidats via viewData('transactions')
    $candidatIds = collect($component->viewData('transactions'))->pluck('id')->map(fn ($i) => (int) $i)->all();

    $banqueIds = Transaction::where('journal', 'banque')->pluck('id')->map(fn ($i) => (int) $i)->all();
    expect(array_intersect($candidatIds, $banqueIds))->toBe([]);
});

it('montantTotal exclut la T4 (pas de double comptage)', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $tiers = tiersJrn();
    $ligne5112 = creerLigne5112SourceJrn($tiers, 80.00, $compte512);
    $sourceTxId = (int) $ligne5112->transaction_id;

    $remise = RemiseBancaire::create([
        'association_id' => TenantContext::currentId(),
        'numero' => 9004, 'date' => '2026-05-26',
        'mode_paiement' => ModePaiement::Cheque,
        'compte_cible_id' => $compteBancaire->id,
        'libelle' => 'Remise total test', 'saisi_par' => userIdJrn(),
    ]);
    app(RemiseBancaireService::class)->comptabiliser($remise, [$sourceTxId]);

    expect($remise->fresh()->montantTotal())->toBe(80.0);
});

it('garde par journal : une écriture banque est exclue du scope operationnel', function () {
    [$compteBancaire, $compte512] = creerCompteBancaireJrn();
    $t1 = creerCreanceJrn(60.00);
    $t2 = app(EcritureGenerator::class)->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $compte512,
        datePaiement: new DateTimeImmutable('2026-05-25'),
        libelle: 'Encaissement défense',
    );

    expect($t2->fresh()->journal->value)->toBe('banque');
    $operationnels = Transaction::query()->operationnel()->pluck('id')->map(fn ($i) => (int) $i)->all();
    expect(in_array((int) $t2->id, $operationnels, true))->toBeFalse();
});
