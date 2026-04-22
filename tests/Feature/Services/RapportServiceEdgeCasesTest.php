<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\TypeTransaction;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RapportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(RapportService::class);
});

it('compteDeResultat returns empty sections when no data exists for exercice', function () {
    $result = $this->service->compteDeResultat(2099);

    expect($result)->toHaveKeys(['charges', 'produits']);
    expect($result['charges'])->toBeEmpty();
    expect($result['produits'])->toBeEmpty();
});

it('fluxTresorerie returns structure with zero balances when no data', function () {
    CompteBancaire::factory()->create();

    $result = $this->service->fluxTresorerie(2099);

    expect($result)->toHaveKeys(['exercice', 'synthese', 'rapprochement', 'mensuel', 'ecritures_non_pointees']);
    expect($result['mensuel'])->toBeArray();
});

it('compteDeResultat handles negative transaction amounts correctly', function () {
    $compte = CompteBancaire::factory()->create();
    $cat = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'date' => '2025-10-15',
        'montant_total' => -50.00,
        'compte_id' => $compte->id,
    ]);

    // Replace auto-generated lines with our controlled one
    TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => -50.00,
    ]);

    $result = $this->service->compteDeResultat(2025);
    expect($result)->toHaveKeys(['charges', 'produits']);
});

it('toCsv generates valid French CSV with semicolons', function () {
    $rows = [
        ['col1' => 'value1', 'col2' => 'value2'],
        ['col1' => 'val;ue3', 'col2' => 'value4'],
    ];
    $csv = $this->service->toCsv($rows, ['col1', 'col2']);

    expect($csv)->toContain(';');
    expect($csv)->toContain('"val;ue3"');
});

it('compteDeResultatOperations returns empty for non-existent operations', function () {
    $result = $this->service->compteDeResultatOperations(2025, [99999]);

    expect($result)->toHaveKeys(['charges', 'produits']);
    expect($result['charges'])->toBeEmpty();
    expect($result['produits'])->toBeEmpty();
});

it('rapportSeances returns empty for non-existent operations', function () {
    $result = $this->service->rapportSeances(2025, [99999]);

    expect($result)->toHaveKeys(['seances', 'charges', 'produits']);
    expect($result['charges'])->toBeEmpty();
    expect($result['produits'])->toBeEmpty();
});

it('toCsv handles empty rows', function () {
    $csv = $this->service->toCsv([], ['col1', 'col2']);

    // Should at least have the header
    expect($csv)->toContain('col1');
    expect($csv)->toContain('col2');
});

it('compteDeResultat exercice boundaries are correct (sept-aug)', function () {
    $compte = CompteBancaire::factory()->create();
    $cat = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    // Transaction in September 2025 = exercice 2025
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'date' => '2025-09-01',
        'montant_total' => 100.00,
        'compte_id' => $compte->id,
    ]);

    // Replace auto-generated lines with our controlled one
    TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 100.00,
    ]);

    $result2025 = $this->service->compteDeResultat(2025);
    $result2024 = $this->service->compteDeResultat(2024);

    // Transaction should appear in exercice 2025 but NOT 2024
    expect($result2025['charges'])->not->toBeEmpty();
    expect($result2024['charges'])->toBeEmpty();
});
