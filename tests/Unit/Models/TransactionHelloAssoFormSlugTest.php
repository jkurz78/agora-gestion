<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\TransactionLigne;

it('persiste le helloasso_form_slug sur la transaction', function (): void {
    $transaction = Transaction::factory()->create([
        'helloasso_form_slug' => 'cotisation-2025',
    ]);

    expect($transaction->fresh()->helloasso_form_slug)->toBe('cotisation-2025');
});

it('persiste le helloasso_tier_id sur la ligne', function (): void {
    $ligne = TransactionLigne::factory()->create([
        'helloasso_tier_id' => 12345,
    ]);

    $fresh = $ligne->fresh();

    expect($fresh->helloasso_tier_id)->toBe(12345)
        ->and($fresh->helloasso_tier_id)->toBeInt();
});
