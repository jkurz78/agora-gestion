<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutPresence;
use App\Livewire\SeanceTable;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'role' => RoleAssociation::Admin,
        'peut_voir_donnees_sensibles' => true,
    ]);
    $this->operation = Operation::factory()->create();
    $this->seance = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'feuille_signee_path' => 'emargement/seance-1.pdf',
        'feuille_signee_at' => now(),
        'feuille_signee_source' => 'email',
    ]);
    $tiers = Tiers::factory()->create();
    $this->participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);
});

it('allows statut update when seance has signed sheet but statut is null', function () {
    Livewire::actingAs($this->user)
        ->test(SeanceTable::class, ['operation' => $this->operation])
        ->call('updatePresence', $this->seance->id, $this->participant->id, 'statut', StatutPresence::Present->value);

    $presence = Presence::where('seance_id', $this->seance->id)
        ->where('participant_id', $this->participant->id)
        ->first();
    expect($presence)->not->toBeNull();
    expect($presence->statut)->toBe(StatutPresence::Present->value);
});

it('rejects statut update when seance has signed sheet and statut already set', function () {
    // Pre-fill the presence with a statut
    Presence::create([
        'seance_id' => $this->seance->id,
        'participant_id' => $this->participant->id,
        'statut' => StatutPresence::Present->value,
    ]);

    Livewire::actingAs($this->user)
        ->test(SeanceTable::class, ['operation' => $this->operation])
        ->call('updatePresence', $this->seance->id, $this->participant->id, 'statut', StatutPresence::Excuse->value);

    $presence = Presence::where('seance_id', $this->seance->id)
        ->where('participant_id', $this->participant->id)
        ->first();
    expect($presence->statut)->toBe(StatutPresence::Present->value);
});

it('allows kine update even when seance has signed sheet', function () {
    Livewire::actingAs($this->user)
        ->test(SeanceTable::class, ['operation' => $this->operation])
        ->call('updatePresence', $this->seance->id, $this->participant->id, 'kine', 'oui');

    $presence = Presence::where('seance_id', $this->seance->id)
        ->where('participant_id', $this->participant->id)
        ->first();
    expect($presence)->not->toBeNull();
    expect($presence->kine)->toBe('oui');
});

it('allows commentaire update even when seance has signed sheet', function () {
    Livewire::actingAs($this->user)
        ->test(SeanceTable::class, ['operation' => $this->operation])
        ->call('updatePresence', $this->seance->id, $this->participant->id, 'commentaire', 'commentaire test');

    $presence = Presence::where('seance_id', $this->seance->id)
        ->where('participant_id', $this->participant->id)
        ->first();
    expect($presence->commentaire)->toBe('commentaire test');
});

it('allows statut update when seance has no signed sheet', function () {
    $this->seance->update([
        'feuille_signee_path' => null,
        'feuille_signee_at' => null,
        'feuille_signee_source' => null,
    ]);

    Livewire::actingAs($this->user)
        ->test(SeanceTable::class, ['operation' => $this->operation])
        ->call('updatePresence', $this->seance->id, $this->participant->id, 'statut', StatutPresence::Present->value);

    $presence = Presence::where('seance_id', $this->seance->id)
        ->where('participant_id', $this->participant->id)
        ->first();
    expect($presence)->not->toBeNull();
    expect($presence->statut)->toBe(StatutPresence::Present->value);
});
