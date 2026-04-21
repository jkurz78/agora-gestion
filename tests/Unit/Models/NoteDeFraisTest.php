<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\NoteDeFrais;
use App\Models\Transaction;

it('cast abandon_creance_propose to bool true when stored as 1', function () {
    $ndf = NoteDeFrais::factory()->create(['abandon_creance_propose' => true]);

    // Reload from DB to simulate MySQL returning 0/1
    $fresh = NoteDeFrais::withoutGlobalScopes()->find($ndf->id);

    expect($fresh->abandon_creance_propose)->toBeBool();
    expect($fresh->abandon_creance_propose)->toBeTrue();
});

it('cast abandon_creance_propose to bool false when stored as 0', function () {
    $ndf = NoteDeFrais::factory()->create(['abandon_creance_propose' => false]);

    $fresh = NoteDeFrais::withoutGlobalScopes()->find($ndf->id);

    expect($fresh->abandon_creance_propose)->toBeBool();
    expect($fresh->abandon_creance_propose)->toBeFalse();
});

it('donTransaction relation returns the linked transaction via don_transaction_id', function () {
    $transaction = Transaction::factory()->create(['type' => TypeTransaction::Recette]);
    $ndf = NoteDeFrais::factory()->create(['don_transaction_id' => $transaction->id]);

    expect($ndf->donTransaction)->not->toBeNull();
    expect((int) $ndf->donTransaction->id)->toBe((int) $transaction->id);
});

it('donTransaction relation returns null when don_transaction_id is null', function () {
    $ndf = NoteDeFrais::factory()->create(['don_transaction_id' => null]);

    expect($ndf->donTransaction)->toBeNull();
});
