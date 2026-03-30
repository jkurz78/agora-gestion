<?php

declare(strict_types=1);

use App\Livewire\ParticipantShow;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->actingAs($this->user);

    $this->typeOp = TypeOperation::factory()->create([
        'formulaire_parcours_therapeutique' => true,
        'formulaire_prescripteur' => true,
        'formulaire_droit_image' => true,
        'formulaire_actif' => true,
    ]);

    $this->operation = Operation::factory()->create([
        'type_operation_id' => $this->typeOp->id,
    ]);

    $this->tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@example.com',
    ]);

    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);
});

it('renders participant show with participant name', function () {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertOk()
        ->assertSee('Marie')
        ->assertSee('Dupont');
});

it('can save coordonnées changes', function () {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->set('editNom', 'Martin')
        ->set('editPrenom', 'Jean')
        ->set('editEmail', 'jean@example.com')
        ->call('save');

    $this->tiers->refresh();
    expect($this->tiers->nom)->toBe('Martin');
    expect($this->tiers->prenom)->toBe('Jean');
    expect($this->tiers->email)->toBe('jean@example.com');
});

it('shows back link to participant list', function () {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertSee('Retour à la liste des participants');
});
