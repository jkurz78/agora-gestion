<?php

declare(strict_types=1);

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->operation = Operation::factory()->create();
    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2025-10-01',
    ]);
});

it('creates an email log with all fields', function () {
    $template = EmailTemplate::create([
        'categorie' => 'formulaire',
        'type_operation_id' => $this->operation->typeOperation->id ?? null,
        'objet' => 'Formulaire à remplir',
        'corps' => '<p>Bonjour</p>',
    ]);

    $log = EmailLog::create([
        'tiers_id' => $this->tiers->id,
        'participant_id' => $this->participant->id,
        'operation_id' => $this->operation->id,
        'categorie' => 'formulaire',
        'email_template_id' => $template->id,
        'destinataire_email' => 'test@example.com',
        'destinataire_nom' => 'Jean Dupont',
        'objet' => 'Formulaire à remplir',
        'statut' => 'envoye',
        'erreur_message' => null,
        'envoye_par' => $this->user->id,
    ]);

    expect($log->exists)->toBeTrue()
        ->and($log->categorie)->toBe('formulaire')
        ->and($log->destinataire_email)->toBe('test@example.com')
        ->and($log->destinataire_nom)->toBe('Jean Dupont')
        ->and($log->objet)->toBe('Formulaire à remplir')
        ->and($log->statut)->toBe('envoye')
        ->and($log->erreur_message)->toBeNull();
});

it('has tiers, participant, operation and envoyePar relationships', function () {
    $log = EmailLog::create([
        'tiers_id' => $this->tiers->id,
        'participant_id' => $this->participant->id,
        'operation_id' => $this->operation->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'test@example.com',
        'objet' => 'Test',
        'statut' => 'envoye',
        'envoye_par' => $this->user->id,
    ]);

    expect($log->tiers->id)->toBe($this->tiers->id)
        ->and($log->participant->id)->toBe($this->participant->id)
        ->and($log->operation->id)->toBe($this->operation->id)
        ->and($log->envoyePar->id)->toBe($this->user->id);
});

it('allows nullable foreign keys', function () {
    $log = EmailLog::create([
        'categorie' => 'formulaire',
        'destinataire_email' => 'test@example.com',
        'objet' => 'Test',
        'statut' => 'envoye',
    ]);

    expect($log->exists)->toBeTrue()
        ->and($log->tiers_id)->toBeNull()
        ->and($log->participant_id)->toBeNull()
        ->and($log->operation_id)->toBeNull()
        ->and($log->email_template_id)->toBeNull()
        ->and($log->envoye_par)->toBeNull();
});

it('stores error status with message', function () {
    $log = EmailLog::create([
        'tiers_id' => $this->tiers->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'fail@example.com',
        'objet' => 'Test',
        'statut' => 'erreur',
        'erreur_message' => 'Connection refused by remote host',
    ]);

    expect($log->statut)->toBe('erreur')
        ->and($log->erreur_message)->toBe('Connection refused by remote host');
});

it('participant has emailLogs relationship', function () {
    EmailLog::create([
        'participant_id' => $this->participant->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'test@example.com',
        'objet' => 'Test',
        'statut' => 'envoye',
    ]);

    expect($this->participant->emailLogs)->toHaveCount(1)
        ->and($this->participant->emailLogs->first()->destinataire_email)->toBe('test@example.com');
});

it('tiers has emailLogs relationship', function () {
    EmailLog::create([
        'tiers_id' => $this->tiers->id,
        'categorie' => 'formulaire',
        'destinataire_email' => 'test@example.com',
        'objet' => 'Test',
        'statut' => 'envoye',
    ]);

    expect($this->tiers->emailLogs)->toHaveCount(1)
        ->and($this->tiers->emailLogs->first()->categorie)->toBe('formulaire');
});
