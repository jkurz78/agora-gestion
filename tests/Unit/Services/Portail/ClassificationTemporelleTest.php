<?php

declare(strict_types=1);

use App\Enums\HorizonTemporel;
use App\Models\Operation;
use App\Models\Seance;
use App\Services\Portail\ClassificationTemporelle;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-05-15'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('classe AVenir une opération avec séances toutes futures', function (): void {
    $op = Operation::factory()->create(['date_debut' => null, 'date_fin' => null]);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2026-06-01']);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2026-07-01']);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::AVenir);
});

it('classe EnCours une opération avec séances chevauchant aujourd\'hui', function (): void {
    $op = Operation::factory()->create(['date_debut' => null, 'date_fin' => null]);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2026-04-01']);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2026-06-01']);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::EnCours);
});

it('classe Terminee une opération avec séances toutes passées', function (): void {
    $op = Operation::factory()->create(['date_debut' => null, 'date_fin' => null]);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2026-03-01']);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2026-04-01']);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::Terminee);
});

it('classe AVenir une opération sans séance avec date_debut future', function (): void {
    $op = Operation::factory()->create([
        'date_debut' => '2026-06-01',
        'date_fin' => '2026-07-31',
    ]);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::AVenir);
});

it('classe EnCours une opération sans séance dont dates encadrent aujourd\'hui', function (): void {
    $op = Operation::factory()->create([
        'date_debut' => '2026-05-01',
        'date_fin' => '2026-05-31',
    ]);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::EnCours);
});

it('classe Terminee une opération sans séance avec date_fin passée', function (): void {
    $op = Operation::factory()->create([
        'date_debut' => '2026-04-01',
        'date_fin' => '2026-04-30',
    ]);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::Terminee);
});

it('classe EnCours par défaut une opération sans séance ni dates', function (): void {
    $op = Operation::factory()->create(['date_debut' => null, 'date_fin' => null]);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::EnCours);
});

it('classe AVenir en ignorant les séances sans date et en utilisant la séance datée future', function (): void {
    $op = Operation::factory()->create(['date_debut' => null, 'date_fin' => null]);
    // 2 séances sans date
    Seance::factory()->create(['operation_id' => $op->id, 'date' => null]);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => null]);
    // 1 séance avec date future
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2026-06-15']);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::AVenir);
});

it('classe via fallback Operation quand toutes les séances sont sans date', function (): void {
    $op = Operation::factory()->create([
        'date_debut' => '2026-05-01',
        'date_fin' => '2026-05-31',
    ]);
    // Séances sans date — doivent être ignorées
    Seance::factory()->create(['operation_id' => $op->id, 'date' => null]);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => null]);

    expect(ClassificationTemporelle::pour($op))->toBe(HorizonTemporel::EnCours);
});
