<?php

declare(strict_types=1);

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has helloasso_payment_id column on transactions', function () {
    expect(Schema::hasColumn('transactions', 'helloasso_payment_id'))->toBeTrue();
});

it('can store helloasso_payment_id on a transaction', function () {
    $tx = Transaction::factory()->create(['helloasso_payment_id' => 99999]);
    expect($tx->fresh()->helloasso_payment_id)->toBe(99999);
});
