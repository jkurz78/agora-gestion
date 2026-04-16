<?php

declare(strict_types=1);

use App\Livewire\ParticipantTable;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->operation = Operation::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

it('renders participant table', function () {
    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertOk()
        ->assertSee('participants');
});

it('shows participants in table', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'prenom' => 'Marie']);
    Participant::create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->assertSee('DUPONT')
        ->assertSee('Marie');
});

it('can add participant via tiers selection then confirm', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->dispatch('tiers-selected', id: $tiers->id)
        ->call('addParticipant');

    expect(Participant::where('tiers_id', $tiers->id)
        ->where('operation_id', $this->operation->id)
        ->exists())->toBeTrue();
});

it('prevents duplicate participant', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    Participant::create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->dispatch('tiers-selected', id: $tiers->id)
        ->call('addParticipant')
        ->assertHasErrors('addTiersId');
});

it('can update tiers field inline', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'telephone' => '0100000000']);
    $participant = Participant::create([
        'association_id' => $this->association->id,
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
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'type' => 'particulier']);
    $participant = Participant::create([
        'association_id' => $this->association->id,
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
        'association_id' => $this->association->id,
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
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

    $type = TypeOperation::factory()->confidentiel()->create(['association_id' => $this->association->id]);
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $type->id,
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $operation])
        ->assertSee('Date naissance')
        ->assertSee('Taille');
});

it('can update medical field inline when permitted', function () {
    $this->user->update(['peut_voir_donnees_sensibles' => true]);

    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $participant = Participant::create([
        'association_id' => $this->association->id,
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
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $participant = Participant::create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('updateMedicalField', $participant->id, 'sexe', 'F');

    $med = ParticipantDonneesMedicales::where('participant_id', $participant->id)->first();
    expect($med)->toBeNull();
});

it('auto-inscribes with today date when participant is added', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Livewire::test(ParticipantTable::class, ['operation' => $this->operation])
        ->call('openAddModal')
        ->dispatch('tiers-selected', id: $tiers->id)
        ->call('addParticipant');

    $participant = Participant::where('tiers_id', $tiers->id)
        ->where('operation_id', $this->operation->id)
        ->first();

    expect($participant)->not->toBeNull();
    expect($participant->date_inscription->format('Y-m-d'))->toBe(now()->format('Y-m-d'));
});

// Edit modal test removed — editing now handled by ParticipantShow component
