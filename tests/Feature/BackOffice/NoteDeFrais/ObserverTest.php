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

// ── Abandon de créance — suppression Transaction Dépense ──────────────────────

test('deleting the depense transaction of an abandon ndf reverts ndf to soumise and soft-deletes the don transaction', function (): void {
    $txDepense = Transaction::factory()->create();
    $txDon = Transaction::factory()->create();

    $ndf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::DonParAbandonCreances->value,
        'transaction_id' => $txDepense->id,
        'don_transaction_id' => $txDon->id,
        'validee_at' => now(),
    ]);

    $txDepense->delete();

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
    expect($ndf->transaction_id)->toBeNull();
    expect($ndf->don_transaction_id)->toBeNull();
    expect($ndf->validee_at)->toBeNull();

    // The don transaction should have been soft-deleted.
    expect(Transaction::withTrashed()->find($txDon->id)?->trashed())->toBeTrue();
});

// ── Abandon de créance — suppression Transaction Don ─────────────────────────

test('deleting the don transaction of an abandon ndf reverts ndf to soumise and soft-deletes the depense transaction', function (): void {
    $txDepense = Transaction::factory()->create();
    $txDon = Transaction::factory()->create();

    $ndf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::DonParAbandonCreances->value,
        'transaction_id' => $txDepense->id,
        'don_transaction_id' => $txDon->id,
        'validee_at' => now(),
    ]);

    $txDon->delete();

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
    expect($ndf->transaction_id)->toBeNull();
    expect($ndf->don_transaction_id)->toBeNull();
    expect($ndf->validee_at)->toBeNull();

    // The depense transaction should have been soft-deleted.
    expect(Transaction::withTrashed()->find($txDepense->id)?->trashed())->toBeTrue();
});

// ── Abandon de créance — suppression séquentielle (idempotence) ───────────────

test('sequential deletion of both abandon transactions is idempotent and raises no error', function (): void {
    $txDepense = Transaction::factory()->create();
    $txDon = Transaction::factory()->create();

    $ndf = NoteDeFrais::factory()->create([
        'statut' => StatutNoteDeFrais::DonParAbandonCreances->value,
        'transaction_id' => $txDepense->id,
        'don_transaction_id' => $txDon->id,
        'validee_at' => now(),
    ]);

    // First deletion reverts the NDF and soft-deletes the sister (txDon).
    $txDepense->delete();

    // Second deletion: txDon is already soft-deleted; observer finds no NDF to revert
    // (both FKs are null), so it is a no-op. Must not throw.
    $txDon->delete();

    $ndf->refresh();
    expect($ndf->getRawOriginal('statut'))->toBe(StatutNoteDeFrais::Soumise->value);
    expect($ndf->transaction_id)->toBeNull();
    expect($ndf->don_transaction_id)->toBeNull();
});
