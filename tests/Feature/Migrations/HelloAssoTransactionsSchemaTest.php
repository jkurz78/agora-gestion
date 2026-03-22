<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('transactions table has helloasso_order_id column', function () {
    expect(Schema::hasColumn('transactions', 'helloasso_order_id'))->toBeTrue();
});

it('transactions table has helloasso_cashout_id column', function () {
    expect(Schema::hasColumn('transactions', 'helloasso_cashout_id'))->toBeTrue();
});

it('can store helloasso fields on a transaction', function () {
    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'helloasso_order_id' => 123456,
        'helloasso_cashout_id' => 789012,
    ]);

    $transaction->refresh();

    expect($transaction->helloasso_order_id)->toBe(123456)
        ->and($transaction->helloasso_cashout_id)->toBe(789012);
});

it('allows null helloasso fields', function () {
    $transaction = Transaction::factory()->create([
        'helloasso_order_id' => null,
        'helloasso_cashout_id' => null,
    ]);

    $transaction->refresh();

    expect($transaction->helloasso_order_id)->toBeNull()
        ->and($transaction->helloasso_cashout_id)->toBeNull();
});

it('rejects duplicate helloasso_order_id for same tiers_id', function () {
    $tiers = Tiers::factory()->create();

    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'helloasso_order_id' => 999,
    ]);

    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'helloasso_order_id' => 999,
    ]);
})->throws(QueryException::class);

it('allows multiple null helloasso_order_id with same tiers_id', function () {
    $tiers = Tiers::factory()->create();

    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'helloasso_order_id' => null,
    ]);

    $second = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'helloasso_order_id' => null,
    ]);

    expect($second->exists)->toBeTrue();
});
