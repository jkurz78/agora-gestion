<?php

use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Services\ExerciceService;
use Illuminate\Database\QueryException;

it('refuse deux enveloppes sur le meme compte et le meme exercice', function () {
    $compte = Compte::factory()->numero('606')->create();
    $exercice = app(ExerciceService::class)->current();

    BudgetLine::factory()->create([
        'compte_id' => $compte->id, 'exercice' => $exercice,
        'operation_id' => null, 'montant_prevu' => 1000.00,
    ]);

    expect(fn () => BudgetLine::factory()->create([
        'compte_id' => $compte->id, 'exercice' => $exercice,
        'operation_id' => null, 'montant_prevu' => 500.00,
    ]))->toThrow(QueryException::class);
});

it('refuse deux ventilations sur la meme operation et le meme compte', function () {
    $compte = Compte::factory()->numero('606')->create();
    $op = Operation::factory()->create();
    $exercice = app(ExerciceService::class)->current();

    BudgetLine::factory()->create([
        'compte_id' => $compte->id, 'exercice' => $exercice,
        'operation_id' => $op->id, 'montant_prevu' => 400.00,
    ]);

    expect(fn () => BudgetLine::factory()->create([
        'compte_id' => $compte->id, 'exercice' => $exercice,
        'operation_id' => $op->id, 'montant_prevu' => 100.00,
    ]))->toThrow(QueryException::class);
});

it('accepte une enveloppe et des ventilations sur le meme compte', function () {
    $compte = Compte::factory()->numero('606')->create();
    $opA = Operation::factory()->create();
    $opB = Operation::factory()->create();
    $exercice = app(ExerciceService::class)->current();

    BudgetLine::factory()->create(['compte_id' => $compte->id, 'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 1000.00]);
    BudgetLine::factory()->create(['compte_id' => $compte->id, 'exercice' => $exercice, 'operation_id' => $opA->id, 'montant_prevu' => 400.00]);
    BudgetLine::factory()->create(['compte_id' => $compte->id, 'exercice' => $exercice, 'operation_id' => $opB->id, 'montant_prevu' => 300.00]);

    expect(BudgetLine::where('compte_id', $compte->id)->count())->toBe(3);
});

it('accepte la meme enveloppe sur deux exercices differents', function () {
    $compte = Compte::factory()->numero('606')->create();
    $exercice = app(ExerciceService::class)->current();

    BudgetLine::factory()->create(['compte_id' => $compte->id, 'exercice' => $exercice, 'operation_id' => null, 'montant_prevu' => 1000.00]);
    BudgetLine::factory()->create(['compte_id' => $compte->id, 'exercice' => $exercice + 1, 'operation_id' => null, 'montant_prevu' => 1100.00]);

    expect(BudgetLine::where('compte_id', $compte->id)->count())->toBe(2);
});
