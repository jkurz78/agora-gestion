<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Models\Tiers;

test('seance belongs to operation', function (): void {
    $operation = Operation::factory()->create();
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2026-01-15',
        'titre' => 'Accueil',
    ]);
    expect($seance->operation->id)->toBe($operation->id);
});

test('seance unique constraint on operation and numero', function (): void {
    $operation = Operation::factory()->create();
    Seance::create(['operation_id' => $operation->id, 'numero' => 1]);
    Seance::create(['operation_id' => $operation->id, 'numero' => 1]);
})->throws(\Illuminate\Database\QueryException::class);

test('operation has many seances ordered by numero', function (): void {
    $operation = Operation::factory()->create();
    Seance::create(['operation_id' => $operation->id, 'numero' => 3]);
    Seance::create(['operation_id' => $operation->id, 'numero' => 1]);
    Seance::create(['operation_id' => $operation->id, 'numero' => 2]);
    $seances = $operation->seances;
    expect($seances->pluck('numero')->toArray())->toBe([1, 2, 3]);
});

test('presence data is encrypted', function (): void {
    $operation = Operation::factory()->create();
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);
    $presence = Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => 'present',
        'kine' => '1',
        'commentaire' => '5 min de retard',
    ]);
    $presence->refresh();
    expect($presence->statut)->toBe('present');
    expect($presence->kine)->toBe('1');
    expect($presence->commentaire)->toBe('5 min de retard');

    $raw = \DB::table('presences')->where('id', $presence->id)->first();
    expect($raw->statut)->not->toBe('present');
});

test('deleting seance cascades to presences', function (): void {
    $operation = Operation::factory()->create();
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);
    Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => 'present',
    ]);
    $seance->delete();
    expect(Presence::count())->toBe(0);
});

test('presence unique constraint on seance and participant', function (): void {
    $operation = Operation::factory()->create();
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);
    Presence::create(['seance_id' => $seance->id, 'participant_id' => $participant->id]);
    Presence::create(['seance_id' => $seance->id, 'participant_id' => $participant->id]);
})->throws(\Illuminate\Database\QueryException::class);
