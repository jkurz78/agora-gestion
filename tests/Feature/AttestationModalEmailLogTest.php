<?php

declare(strict_types=1);

use App\Enums\StatutPresence;
use App\Livewire\AttestationModal;
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
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();

    $this->association = Association::factory()->create([
        'nom' => 'Asso Test',
        'ville' => 'Paris',
    ]);
    TenantContext::boot($this->association);

    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    $this->typeOp = TypeOperation::factory()->create([
        'email_from' => 'asso@test.com',
        'email_from_name' => 'Asso Test',
        'association_id' => $this->association->id,
    ]);

    $this->operation = Operation::factory()->create([
        'type_operation_id' => $this->typeOp->id,
        'association_id' => $this->association->id,
    ]);

    $this->seance = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => '2026-03-15',
    ]);

    $this->tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@test.com',
        'association_id' => $this->association->id,
    ]);

    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    Presence::create([
        'seance_id' => $this->seance->id,
        'participant_id' => $this->participant->id,
        'statut' => StatutPresence::Present->value,
    ]);

    EmailTemplate::create([
        'association_id' => $this->association->id,
        'categorie' => 'attestation',
        'objet' => 'Attestation séance — {operation}',
        'corps' => '<p>Bonjour {prenom}, voici votre attestation pour {type_operation}.</p>',
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('enregistre un EmailLog avec corps_html et attachment_path après envoi attestation séance', function (): void {
    Livewire::test(AttestationModal::class, ['operation' => $this->operation])
        ->call('openSeanceModal', $this->seance->id)
        ->call('envoyerParEmail')
        ->assertSee('1 envoyé(s)');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();

    // corps_html doit être rempli (bug corrigé)
    expect($log->corps_html)->not->toBeNull();
    expect($log->corps_html)->toContain('Marie');

    // attachment_path doit pointer vers le dossier tenant (bug corrigé)
    expect($log->attachment_path)->not->toBeNull();
    expect($log->attachment_path)->toStartWith("associations/{$this->association->id}/email_attachments/");

    // Le fichier doit exister sur le disque local fake
    Storage::disk('local')->assertExists($log->attachment_path);

    // catégorie et statut corrects
    expect($log->categorie)->toBe('attestation');
    expect($log->statut)->toBe('envoye');

    // participant_id et operation_id liés correctement
    expect((int) $log->participant_id)->toBe((int) $this->participant->id);
    expect((int) $log->operation_id)->toBe((int) $this->operation->id);
});

it('enregistre un EmailLog avec corps_html et attachment_path après envoi attestation récap', function (): void {
    // openRecapModal sets mode='recap', then envoyerParEmail dispatches to envoyerRecapParEmail
    Livewire::test(AttestationModal::class, ['operation' => $this->operation])
        ->call('openRecapModal', $this->participant->id)
        ->call('envoyerParEmail')
        ->assertSee('Email envoyé à');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();

    // corps_html doit être rempli
    expect($log->corps_html)->not->toBeNull();
    expect($log->corps_html)->toContain('Marie');

    // attachment_path doit pointer vers le dossier tenant
    expect($log->attachment_path)->not->toBeNull();
    expect($log->attachment_path)->toStartWith("associations/{$this->association->id}/email_attachments/");

    Storage::disk('local')->assertExists($log->attachment_path);

    expect($log->categorie)->toBe('attestation');
    expect($log->statut)->toBe('envoye');
    expect((int) $log->participant_id)->toBe((int) $this->participant->id);
});

it('enregistre un EmailLog erreur pour séance sans corps_html ni attachment_path', function (): void {
    // Force Mail to throw
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP timeout'));

    Livewire::test(AttestationModal::class, ['operation' => $this->operation])
        ->call('openSeanceModal', $this->seance->id)
        ->call('envoyerParEmail')
        ->assertSee('1 erreur(s)');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();
    expect($log->statut)->toBe('erreur');
    expect($log->erreur_message)->toBe('SMTP timeout');
    expect($log->corps_html)->toBeNull();
    expect($log->attachment_path)->toBeNull();
    expect($log->categorie)->toBe('attestation');
});
