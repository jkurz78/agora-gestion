<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;

// Exercice 2025 = Sept 1 2025 → Aug 31 2026 (time frozen to 2026-01-15 in Pest.php)

it('Transaction::forExercice includes date on first day of exercice', function () {
    $transaction = Transaction::factory()->create(['date' => '2025-09-01']);

    $found = Transaction::forExercice(2025)->where('id', $transaction->id)->exists();

    expect($found)->toBeTrue();
});

it('Transaction::forExercice includes date on last day of exercice', function () {
    $transaction = Transaction::factory()->create(['date' => '2026-08-31']);

    $found = Transaction::forExercice(2025)->where('id', $transaction->id)->exists();

    expect($found)->toBeTrue();
});

it('Transaction::forExercice excludes date one day before exercice start', function () {
    $transaction = Transaction::factory()->create(['date' => '2025-08-31']);

    $found = Transaction::forExercice(2025)->where('id', $transaction->id)->exists();

    expect($found)->toBeFalse();
});

it('Transaction::forExercice excludes date one day after exercice end', function () {
    $transaction = Transaction::factory()->create(['date' => '2026-09-01']);

    $found = Transaction::forExercice(2025)->where('id', $transaction->id)->exists();

    expect($found)->toBeFalse();
});

it('Transaction::forExercice uses tenant exercice_mois_debut', function () {
    $asso = TenantContext::current();
    $asso->update(['exercice_mois_debut' => 1]);

    $range = app(ExerciceService::class)->dateRange(2025);
    expect($range['start']->toDateString())->toBe('2025-01-01');
    expect($range['end']->toDateString())->toBe('2025-12-31');

    $inScope = Transaction::factory()->create(['date' => '2025-06-15']);
    $outScope = Transaction::factory()->create(['date' => '2026-01-01']);

    expect(Transaction::forExercice(2025)->where('id', $inScope->id)->exists())->toBeTrue();
    expect(Transaction::forExercice(2025)->where('id', $outScope->id)->exists())->toBeFalse();
});

it('VirementInterne::forExercice includes boundary dates', function () {
    $vIn = VirementInterne::factory()->create(['date' => '2025-09-01']);
    $vOut = VirementInterne::factory()->create(['date' => '2025-08-31']);

    expect(VirementInterne::forExercice(2025)->where('id', $vIn->id)->exists())->toBeTrue();
    expect(VirementInterne::forExercice(2025)->where('id', $vOut->id)->exists())->toBeFalse();
});

it('Transaction::forExercice and ExerciceService::dateRange agree on boundaries', function () {
    $range = app(ExerciceService::class)->dateRange(2025);

    $onStart = Transaction::factory()->create(['date' => $range['start']->toDateString()]);
    $onEnd = Transaction::factory()->create(['date' => $range['end']->toDateString()]);
    $beforeStart = Transaction::factory()->create(['date' => $range['start']->subDay()->toDateString()]);
    $afterEnd = Transaction::factory()->create(['date' => $range['end']->addDay()->toDateString()]);

    $ids = Transaction::forExercice(2025)->pluck('id');

    expect($ids)->toContain($onStart->id)
        ->toContain($onEnd->id)
        ->not->toContain($beforeStart->id)
        ->not->toContain($afterEnd->id);
});
