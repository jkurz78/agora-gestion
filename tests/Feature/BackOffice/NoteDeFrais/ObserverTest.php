<?php

declare(strict_types=1);

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

test('softdeleting a transaction linked to a ndf reverts ndf to soumise', function (): void {
    $transaction = Transaction::factory()->create();
    $ndf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::Validee->value,
        'transaction_id' => $transaction->id,
        'validee_at' => now(),
    ]);

    $transaction->delete();

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
    expect($ndf->transaction_id)->toBeNull();
    expect($ndf->validee_at)->toBeNull();
});

test('force-deleting a transaction linked to a ndf reverts ndf to soumise', function (): void {
    $transaction = Transaction::factory()->create();
    $ndf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::Validee->value,
        'transaction_id' => $transaction->id,
        'validee_at' => now(),
    ]);

    $transaction->forceDelete();

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
    expect($ndf->transaction_id)->toBeNull();
    expect($ndf->validee_at)->toBeNull();
});

test('deleting a transaction not linked to any ndf does not touch ndf records', function (): void {
    $transaction = Transaction::factory()->create();
    $unrelatedNdf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::Validee->value,
        'transaction_id' => null,
        'validee_at' => now(),
    ]);

    $transaction->delete();

    $unrelatedNdf->refresh();
    expect($unrelatedNdf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Validee->value);
    expect($unrelatedNdf->validee_at)->not->toBeNull();
});

test('observer emits comptabilite.ndf.reverted_to_submitted log on softdelete', function (): void {
    $spy = Log::spy();

    $transaction = Transaction::factory()->create();
    $ndf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::Validee->value,
        'transaction_id' => $transaction->id,
        'validee_at' => now(),
    ]);

    $transaction->delete();

    $spy->shouldHaveReceived('info')
        ->with(
            'comptabilite.ndf.reverted_to_submitted',
            Mockery::on(fn ($ctx) => (int) ($ctx['ndf_id'] ?? 0) === (int) $ndf->id
                && (int) ($ctx['transaction_id'] ?? 0) === (int) $transaction->id)
        )
        ->once();
});

test('observer emits comptabilite.ndf.reverted_to_submitted log on force-delete', function (): void {
    $spy = Log::spy();

    $transaction = Transaction::factory()->create();
    $ndf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::Validee->value,
        'transaction_id' => $transaction->id,
        'validee_at' => now(),
    ]);

    $transaction->forceDelete();

    $spy->shouldHaveReceived('info')
        ->with(
            'comptabilite.ndf.reverted_to_submitted',
            Mockery::on(fn ($ctx) => (int) ($ctx['ndf_id'] ?? 0) === (int) $ndf->id
                && (int) ($ctx['transaction_id'] ?? 0) === (int) $transaction->id)
        )
        ->once();
});
