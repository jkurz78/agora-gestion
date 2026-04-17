<?php

declare(strict_types=1);

use App\Enums\StatutPresence;
use App\Livewire\AttestationModal;
use App\Mail\AttestationPresenceMail;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create(['nom' => 'Test Asso', 'ville' => 'Paris']);
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('association has cachet_signature_path field', function () {
    $this->association->fill(['cachet_signature_path' => 'association/cachet.png'])->save();
    $asso = $this->association->fresh();
    expect($asso->cachet_signature_path)->toBe('association/cachet.png');
});

it('generates attestation PDF for a seance', function () {
    $typeOp = TypeOperation::factory()->create(['association_id' => $this->association->id]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'association_id' => $this->association->id,
    ]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => StatutPresence::Present->value,
    ]);

    $response = $this->get(route('operations.seances.attestation-pdf', [
        $operation, $seance, 'participants' => $participant->id,
    ]));

    $response->assertOk();
});

it('generates recap attestation PDF for a participant', function () {
    $typeOp = TypeOperation::factory()->create(['association_id' => $this->association->id]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'association_id' => $this->association->id,
    ]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => StatutPresence::Present->value,
    ]);

    $response = $this->get(route('operations.participants.attestation-recap-pdf', [
        $operation, $participant,
    ]));

    $response->assertOk();
});

it('rejects attestation PDF for non-present participant', function () {
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    // No Presence record

    $response = $this->get(route('operations.seances.attestation-pdf', [
        $operation, $seance, 'participants' => $participant->id,
    ]));

    $response->assertStatus(404);
});

it('creates attestation mail with PDF attachment and substituted variables', function () {
    $mail = new AttestationPresenceMail(
        prenomParticipant: 'Marie',
        nomParticipant: 'Dupont',
        nomOperation: 'Parcours 1',
        nomTypeOperation: 'PSA',
        dateDebut: '15/09/2025',
        dateFin: '30/06/2026',
        nombreSeances: '30',
        numeroSeance: '5',
        dateSeance: '15/03/2026',
        customObjet: 'Attestation — {operation}',
        customCorps: '<p>Bonjour {prenom}, voici votre attestation pour la séance n°{numero_seance}.</p>',
        pdfContent: '%PDF-fake-content',
        pdfFilename: 'attestation.pdf',
    );

    expect($mail->envelope()->subject)->toBe('Attestation — Parcours 1');
    expect($mail->corpsHtml)->toContain('Bonjour Marie');
    expect($mail->corpsHtml)->toContain('séance n°5');
    expect($mail->attachments())->toHaveCount(1);
});

// ── Livewire AttestationModal tests ─────────────────────────────

it('opens seance attestation modal with present participants', function () {
    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'asso@test.com',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@test.com',
        'association_id' => $this->association->id,
    ]);
    $participant = Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    Presence::create(['seance_id' => $seance->id, 'participant_id' => $participant->id, 'statut' => StatutPresence::Present->value]);

    Livewire::test(AttestationModal::class, ['operation' => $operation])
        ->call('openSeanceModal', $seance->id)
        ->assertSee('DUPONT')
        ->assertSee('Marie');
});

it('sends attestation emails and logs them', function () {
    Mail::fake();

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'asso@test.com',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15']);
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@test.com',
        'association_id' => $this->association->id,
    ]);
    $participant = Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    Presence::create(['seance_id' => $seance->id, 'participant_id' => $participant->id, 'statut' => StatutPresence::Present->value]);

    EmailTemplate::create([
        'association_id' => $this->association->id,
        'categorie' => 'attestation',
        'objet' => 'Attestation — {operation}',
        'corps' => '<p>Bonjour {prenom}</p>',
    ]);

    Livewire::test(AttestationModal::class, ['operation' => $operation])
        ->call('openSeanceModal', $seance->id)
        ->call('envoyerParEmail')
        ->assertSee('1 envoyé(s)');

    $log = EmailLog::where('participant_id', $participant->id)->where('categorie', 'attestation')->first();
    expect($log)->not->toBeNull()
        ->and($log->statut)->toBe('envoye');
});

it('opens recap modal with seances list', function () {
    $typeOp = TypeOperation::factory()->create(['association_id' => $this->association->id]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2026-03-15', 'titre' => 'Introduction']);
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'association_id' => $this->association->id,
    ]);
    $participant = Participant::create(['tiers_id' => $tiers->id, 'operation_id' => $operation->id, 'date_inscription' => now()]);
    Presence::create(['seance_id' => $seance->id, 'participant_id' => $participant->id, 'statut' => StatutPresence::Present->value]);

    Livewire::test(AttestationModal::class, ['operation' => $operation])
        ->call('openRecapModal', $participant->id)
        ->assertSee('Introduction')
        ->assertSee('1 séance(s) sur 1');
});
