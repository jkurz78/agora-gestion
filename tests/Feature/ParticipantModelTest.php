<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\User;

test('participant belongs to tiers and operation', function (): void {
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);
    expect($participant->tiers->id)->toBe($tiers->id);
    expect($participant->operation->id)->toBe($operation->id);
});

test('participant unique constraint on tiers and operation', function (): void {
    $tiers = Tiers::factory()->create();
    $operation = Operation::factory()->create();
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()->toDateString()]);
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()->toDateString()]);
})->throws(\Illuminate\Database\QueryException::class);

test('tiers can participate in multiple operations', function (): void {
    $tiers = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id, 'date_inscription' => now()]);
    Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $op2->id, 'date_inscription' => now()]);
    expect($tiers->participants)->toHaveCount(2);
});

test('operation has many participants', function (): void {
    $operation = Operation::factory()->create();
    $t1 = Tiers::factory()->create();
    $t2 = Tiers::factory()->create();
    Participant::create(['tiers_id' => $t1->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    Participant::create(['tiers_id' => $t2->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    expect($operation->participants)->toHaveCount(2);
});

test('donnees medicales are encrypted and linked to participant', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => Operation::factory()->create()->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $donnees = ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1985-06-15',
        'sexe' => 'F',
        'poids' => '65',
    ]);
    $donnees->refresh();
    expect($donnees->date_naissance)->toBe('1985-06-15');
    expect($donnees->sexe)->toBe('F');
    expect($donnees->poids)->toBe('65');
    $raw = \DB::table('participant_donnees_medicales')->where('id', $donnees->id)->first();
    expect($raw->date_naissance)->not->toBe('1985-06-15');
});

test('deleting participant cascades to donnees medicales', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => Operation::factory()->create()->id,
        'date_inscription' => now()->toDateString(),
    ]);
    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1990-01-01',
        'sexe' => 'M',
        'poids' => '80',
    ]);
    $participant->delete();
    expect(ParticipantDonneesMedicales::count())->toBe(0);
});

test('user peut_voir_donnees_sensibles defaults to false', function (): void {
    $user = User::factory()->create();
    expect($user->peut_voir_donnees_sensibles)->toBeFalse();
});

test('participant donnees medicales has unique constraint on participant_id', function (): void {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => Operation::factory()->create()->id,
        'date_inscription' => now()->toDateString(),
    ]);
    ParticipantDonneesMedicales::create(['participant_id' => $participant->id]);
    ParticipantDonneesMedicales::create(['participant_id' => $participant->id]);
})->throws(\Illuminate\Database\QueryException::class);
