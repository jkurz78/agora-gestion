<?php

use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\BudgetService;

beforeEach(function () {
    $this->service = new BudgetService;
    $this->user = User::factory()->create();
});

it('rattache une ligne non eclatee a son operation', function () {
    $compte = Compte::factory()->numero('606')->create();
    $op = Operation::factory()->create();

    $tx = Transaction::factory()->asDepense()->create(['date' => '2025-10-10', 'saisi_par' => $this->user->id]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => 200.00, 'debit' => 200.00, 'credit' => 0.00,
        'operation_id' => $op->id,
    ]);

    $carte = $this->service->realiseParCompteEtOperation(2025);

    expect($carte[(int) $compte->id][(int) $op->id])->toBe(200.0);
});

it('repartit une ligne eclatee sur ses affectations', function () {
    $compte = Compte::factory()->numero('606')->create();
    $opA = Operation::factory()->create();
    $opB = Operation::factory()->create();

    $tx = Transaction::factory()->asDepense()->create(['date' => '2025-10-11', 'saisi_par' => $this->user->id]);
    $tx->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => 300.00, 'debit' => 300.00, 'credit' => 0.00,
        'operation_id' => $opA->id,
    ]);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id, 'operation_id' => $opA->id, 'montant' => 200.00,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id, 'operation_id' => $opB->id, 'montant' => 100.00,
    ]);

    $carte = $this->service->realiseParCompteEtOperation(2025);

    expect($carte[(int) $compte->id][(int) $opA->id])->toBe(200.0)
        ->and($carte[(int) $compte->id][(int) $opB->id])->toBe(100.0);
});

it('rend negatif un contra-produit eclate', function () {
    $compte = Compte::factory()->numero('709')->create();
    $opA = Operation::factory()->create();
    $opB = Operation::factory()->create();

    $tx = Transaction::factory()->asRecette()->create(['date' => '2025-10-12', 'saisi_par' => $this->user->id]);
    $tx->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => 50.00, 'debit' => 50.00, 'credit' => 0.00,
        'operation_id' => $opA->id,
    ]);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id, 'operation_id' => $opA->id, 'montant' => 30.00,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id, 'operation_id' => $opB->id, 'montant' => 20.00,
    ]);

    $carte = $this->service->realiseParCompteEtOperation(2025);

    expect($carte[(int) $compte->id][(int) $opA->id])->toBe(-30.0)
        ->and($carte[(int) $compte->id][(int) $opB->id])->toBe(-20.0);
});

it('garde le total du compte egal a la somme de ses ventilations et de sa part non imputee', function () {
    $compte = Compte::factory()->numero('606')->create();
    $op = Operation::factory()->create();

    $tx = Transaction::factory()->asDepense()->create(['date' => '2025-10-13', 'saisi_par' => $this->user->id]);
    $tx->lignes()->forceDelete();

    // Une ligne rattachée à l'opération…
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id, 'compte_id' => $compte->id,
        'montant' => 200.00, 'debit' => 200.00, 'credit' => 0.00,
        'operation_id' => $op->id,
    ]);
    // …et une ligne sans opération.
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id, 'compte_id' => $compte->id,
        'montant' => 80.00, 'debit' => 80.00, 'credit' => 0.00,
        'operation_id' => null,
    ]);

    $parCompte = $this->service->realiseParCompte(2025);
    $parOperation = $this->service->realiseParCompteEtOperation(2025);

    $sommeVentilee = array_sum($parOperation[(int) $compte->id] ?? []);

    expect($parCompte[(int) $compte->id])->toBe(280.0)
        ->and($sommeVentilee)->toBe(200.0)
        ->and($parCompte[(int) $compte->id] - $sommeVentilee)->toBe(80.0);
});

it('affirme que le montant d une affectation est une magnitude positive', function () {
    $negatives = App\Models\TransactionLigneAffectation::where('montant', '<', 0)->count();

    expect($negatives)->toBe(0);
});
