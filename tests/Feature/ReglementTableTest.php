<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
});

it('can create a reglement', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);

    expect($reglement)->not->toBeNull();
    expect($reglement->mode_paiement)->toBe(ModePaiement::Cheque);
    expect((float) $reglement->montant_prevu)->toBe(30.00);
});

it('enforces unique participant-seance constraint', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 50.00,
    ]);
})->throws(\Illuminate\Database\QueryException::class);

it('cascades delete when seance is deleted', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    $seance->delete();
    expect(Reglement::count())->toBe(0);
});

it('has participant and seance relationships', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 30.00,
    ]);

    expect($reglement->participant->id)->toBe($participant->id);
    expect($reglement->seance->id)->toBe($seance->id);
    expect($participant->reglements)->toHaveCount(1);
    expect($seance->reglements)->toHaveCount(1);
});
