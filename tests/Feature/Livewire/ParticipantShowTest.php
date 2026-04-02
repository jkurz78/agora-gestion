<?php

declare(strict_types=1);

use App\Livewire\ParticipantShow;
use App\Models\EmailLog;
use App\Models\FormulaireToken;
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

it('can save coordonnées changes and shows success message', function () {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->set('editNom', 'Martin')
        ->set('editPrenom', 'Jean')
        ->set('editEmail', 'jean@example.com')
        ->call('save')
        ->assertSet('successMessage', 'Modifications enregistrées.')
        ->assertSee('Modifications enregistrées.');

    $this->tiers->refresh();
    expect($this->tiers->nom)->toBe('Martin');
    expect($this->tiers->prenom)->toBe('Jean');
    expect($this->tiers->email)->toBe('jean@example.com');
});

it('shows breadcrumb with operation name', function () {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertSee($this->operation->nom)
        ->assertSee('Enregistrer');
});

it('shows historique tab with email logs', function () {
    EmailLog::create([
        'participant_id' => $this->participant->id,
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'marie@test.com',
        'destinataire_nom' => 'Dupont Marie',
        'objet' => 'Votre formulaire',
        'statut' => 'envoye',
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertSee('Votre formulaire')
        ->assertSee('marie@test.com');
});

it('shows formulaire rempli in historique', function () {
    FormulaireToken::create([
        'participant_id' => $this->participant->id,
        'token' => 'ABCD-EFGH',
        'expire_at' => '2026-12-31',
        'rempli_at' => '2026-03-15 14:30:00',
        'rempli_ip' => '82.123.45.67',
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertSee('Formulaire rempli')
        ->assertSee('15/03/2026');
});
