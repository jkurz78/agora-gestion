<?php

declare(strict_types=1);

use App\Livewire\ParticipantList;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
});

it('renders participant list on operation page', function () {
    $this->get(route('operations.show', $this->operation))
        ->assertOk()
        ->assertSee('Participants');
});

it('shows enrolled participants', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-03-01',
    ]);

    Livewire::test(ParticipantList::class, ['operation' => $this->operation])
        ->assertSee('Jean Dupont');
});

it('can add a participant', function () {
    $tiers = Tiers::factory()->create();

    Livewire::test(ParticipantList::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->set('selectedTiersId', $tiers->id)
        ->set('dateInscription', '2026-03-15')
        ->set('notes', 'Test note')
        ->call('addParticipant')
        ->assertSet('showAddModal', false);

    $this->assertDatabaseHas('participants', [
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-03-15',
        'notes' => 'Test note',
    ]);
});

it('cannot add the same participant twice', function () {
    $tiers = Tiers::factory()->create();
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-03-01',
    ]);

    Livewire::test(ParticipantList::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->set('selectedTiersId', $tiers->id)
        ->set('dateInscription', '2026-03-15')
        ->call('addParticipant')
        ->assertHasErrors('selectedTiersId');
});

it('can remove a participant', function () {
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-03-01',
    ]);

    Livewire::test(ParticipantList::class, ['operation' => $this->operation])
        ->call('removeParticipant', $participant->id);

    $this->assertDatabaseMissing('participants', ['id' => $participant->id]);
});

it('hides medical data when user lacks permission', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Sophie']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-03-01',
    ]);
    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1990-05-15',
        'sexe' => 'F',
        'poids' => '65',
    ]);

    Livewire::test(ParticipantList::class, ['operation' => $this->operation])
        ->assertSee('Sophie Martin')
        ->assertDontSee('Date naissance')
        ->assertDontSee('heart-pulse');
});

it('shows medical data when user has permission', function () {
    $this->user->update(['peut_voir_donnees_sensibles' => true]);

    $tiers = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Sophie']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-03-01',
    ]);
    ParticipantDonneesMedicales::create([
        'participant_id' => $participant->id,
        'date_naissance' => '1990-05-15',
        'sexe' => 'F',
        'poids' => '65',
    ]);

    Livewire::test(ParticipantList::class, ['operation' => $this->operation])
        ->assertSee('Sophie Martin')
        ->assertSee('Date naissance')
        ->assertSee('15/05/1990')
        ->assertSeeHtml('heart-pulse');
});
