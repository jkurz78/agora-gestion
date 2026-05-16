<?php

declare(strict_types=1);

use App\Livewire\ParticipantTable;
use App\Models\Association;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    Mail::fake();

    $this->association = Association::factory()->create([
        'email_from' => 'asso@example.com',
        'email_from_name' => 'Association Test',
    ]);
    TenantContext::boot($this->association);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    $sousCategorie = SousCategorie::factory()->create();
    $this->typeOp = TypeOperation::factory()->create([
        'sous_categorie_id' => $sousCategorie->id,
        'email_from' => 'asso@example.com',
        'email_from_name' => 'Association Test',
        'association_id' => $this->association->id,
    ]);

    $this->operation = Operation::factory()->create([
        'type_operation_id' => $this->typeOp->id,
        'association_id' => $this->association->id,
    ]);

    $this->tiers = Tiers::factory()->create([
        'email' => 'jean@example.com',
        'prenom' => 'Jean',
        'nom' => 'DUPONT',
        'association_id' => $this->association->id,
    ]);

    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    EmailTemplate::create([
        'association_id' => $this->association->id,
        'categorie' => 'formulaire',
        'type_operation_id' => null,
        'objet' => 'Formulaire à compléter — {operation}',
        'corps' => '<p>Bonjour {prenom}, veuillez remplir le formulaire.</p>',
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('enregistre un EmailLog avec corps_html après envoi formulaire invitation', function (): void {
    // genererToken creates the token and populates modal state; then envoyerTokenParEmail sends the email
    $component = Livewire::test(ParticipantTable::class, ['operation' => $this->operation]);

    $component->call('genererToken', $this->participant->id);
    $component->call('envoyerTokenParEmail');

    $component->assertSee('Email envoyé à');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();

    // corps_html doit être rempli (bug corrigé)
    expect($log->corps_html)->not->toBeNull();
    expect($log->corps_html)->toContain('Jean');

    // Aucun PDF pour le formulaire — attachment_path doit être null
    expect($log->attachment_path)->toBeNull();

    expect($log->categorie)->toBe('formulaire');
    expect($log->statut)->toBe('envoye');
    expect((int) $log->participant_id)->toBe((int) $this->participant->id);
    expect((int) $log->operation_id)->toBe((int) $this->operation->id);

    // tracking_token persisté + pixel embarqué dans corps_html (ouvertures activées)
    expect($log->tracking_token)->not->toBeNull()->toHaveLength(32);
    expect($log->corps_html)->toContain('/t/'.$log->tracking_token.'.gif');
});

it('enregistre un EmailLog erreur pour formulaire avec corps_html null', function (): void {
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP error'));

    $component = Livewire::test(ParticipantTable::class, ['operation' => $this->operation]);
    $component->call('genererToken', $this->participant->id);
    $component->call('envoyerTokenParEmail');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();
    expect($log->statut)->toBe('erreur');
    expect($log->erreur_message)->toBe('SMTP error');
    expect($log->corps_html)->toBeNull();
    expect($log->attachment_path)->toBeNull();
    expect($log->categorie)->toBe('formulaire');
});
