<?php

declare(strict_types=1);

use App\Livewire\ParticipantTable;
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

it('renders participant table', function () {
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertOk()
        ->assertSee('participants');
});

it('shows participants in table', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSee('Dupont')
        ->assertSee('Marie');
});

it('can add participant via tiers-selected event', function () {
    $tiers = Tiers::factory()->create();

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->dispatch('tiers-selected', id: $tiers->id);

    expect(Participant::where('tiers_id', $tiers->id)
        ->where('operation_id', $this->operation->id)
        ->exists())->toBeTrue();
});

it('prevents duplicate participant', function () {
    $tiers = Tiers::factory()->create();
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->dispatch('tiers-selected', id: $tiers->id)
        ->assertHasErrors('addTiersId');
});

it('can update tiers field inline', function () {
    $tiers = Tiers::factory()->create(['telephone' => '0100000000']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('updateTiersField', $participant->id, 'telephone', '0600000000');

    $tiers->refresh();
    expect($tiers->telephone)->toBe('0600000000');
});

it('rejects disallowed tiers fields', function () {
    $tiers = Tiers::factory()->create(['type' => 'particulier']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('updateTiersField', $participant->id, 'type', 'entreprise');

    $tiers->refresh();
    expect($tiers->type)->toBe('particulier');
});

it('can remove participant', function () {
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('removeParticipant', $participant->id);

    expect(Participant::find($participant->id))->toBeNull();
});

it('hides medical columns when user lacks permission', function () {
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertDontSee('Date naissance')
        ->assertDontSee('Taille');
});

it('shows medical columns when user has permission', function () {
    $this->user->update(['peut_voir_donnees_sensibles' => true]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSee('Date naissance')
        ->assertSee('Taille');
});

it('can update medical field inline when permitted', function () {
    $this->user->update(['peut_voir_donnees_sensibles' => true]);

    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('updateMedicalField', $participant->id, 'sexe', 'F');

    $med = ParticipantDonneesMedicales::where('participant_id', $participant->id)->first();
    expect($med)->not->toBeNull();
    expect($med->sexe)->toBe('F');
});

it('blocks medical field update when not permitted', function () {
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('updateMedicalField', $participant->id, 'sexe', 'F');

    $med = ParticipantDonneesMedicales::where('participant_id', $participant->id)->first();
    expect($med)->toBeNull();
});

it('auto-inscribes with today date when tiers is selected', function () {
    $tiers = Tiers::factory()->create();

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->dispatch('tiers-selected', id: $tiers->id);

    $participant = Participant::where('tiers_id', $tiers->id)
        ->where('operation_id', $this->operation->id)
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->date_inscription->format('Y-m-d'))->toBe(now()->format('Y-m-d'));
});

it('can open and save edit modal', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Avant', 'prenom' => 'Test']);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-01',
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openEditModal', $participant->id)
        ->assertSet('editNom', 'Avant')
        ->set('editNom', 'Apres')
        ->call('saveEdit');

    $tiers->refresh();
    expect($tiers->nom)->toBe('Apres');
});
