<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a HelloAsso enum case with value helloasso', function () {
    $case = ModePaiement::HelloAsso;

    expect($case->value)->toBe('helloasso');
});

it('has HelloAsso as label for the helloasso case', function () {
    expect(ModePaiement::HelloAsso->label())->toBe('HelloAsso');
});

it('can create a transaction with mode_paiement helloasso', function () {
    $transaction = Transaction::factory()->create([
        'mode_paiement' => ModePaiement::HelloAsso,
    ]);

    $transaction->refresh();

    expect($transaction->mode_paiement)->toBe(ModePaiement::HelloAsso);
});
