<?php

declare(strict_types=1);

use App\Livewire\SeanceTable;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

it('renders seance table', function () {
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->assertOk()
        ->assertSee('séances');
});

it('can add a seance', function () {
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->call('addSeance');
    expect(Seance::where('operation_id', $this->operation->id)->count())->toBe(1);
    expect(Seance::first()->numero)->toBe(1);
});

it('increments seance numero', function () {
    Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->call('addSeance');
    expect(Seance::where('operation_id', $this->operation->id)->count())->toBe(2);
    expect(Seance::where('numero', 2)->exists())->toBeTrue();
});

it('can update presence', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->call('updatePresence', $seance->id, $participant->id, 'statut', 'present');
    $presence = Presence::where('seance_id', $seance->id)->where('participant_id', $participant->id)->first();
    expect($presence)->not->toBeNull();
    expect($presence->statut)->toBe('present');
});

it('can remove seance', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->call('removeSeance', $seance->id);
    expect(Seance::find($seance->id))->toBeNull();
});
