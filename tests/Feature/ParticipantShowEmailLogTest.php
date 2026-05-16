<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Livewire\ParticipantShow;
use App\Models\Association;
use App\Models\DocumentPrevisionnel;
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
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('local');
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
    ]);

    $this->operation = Operation::factory()->create([
        'type_operation_id' => $this->typeOp->id,
    ]);

    $this->tiers = Tiers::factory()->create([
        'email' => 'jean@example.com',
        'prenom' => 'Jean',
        'nom' => 'DUPONT',
    ]);

    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    // Create a devis document previsionnel linked to the participant
    $this->doc = DocumentPrevisionnel::factory()->devis()->create([
        'operation_id' => $this->operation->id,
        'participant_id' => $this->participant->id,
        'version' => 1,
        'date' => now()->toDateString(),
        'montant_total' => 150.00,
        'lignes_json' => [],
    ]);

    // Email template for the Document category
    $this->template = EmailTemplate::create([
        'association_id' => $this->association->id,
        'categorie' => CategorieEmail::Document->value,
        'type_operation_id' => null,
        'objet' => 'Votre devis n° {numero_document}',
        'corps' => '<p>Bonjour {prenom},</p><p>Veuillez trouver votre devis ci-joint.</p>',
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('enregistre un EmailLog avec corps_html et attachment_path après envoi de devis depuis ParticipantShow', function (): void {
    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->call('envoyerDocumentEmail', $this->doc->id);

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();

    // corps_html doit être rempli (bug 3 fixé)
    expect($log->corps_html)->not->toBeNull();
    expect($log->corps_html)->toContain('Jean');

    // attachment_path doit pointer vers le dossier tenant (bug 4 fixé)
    expect($log->attachment_path)->not->toBeNull();
    expect($log->attachment_path)->toStartWith("associations/{$this->association->id}/email_attachments/");

    // Le fichier doit exister sur le disque local fake
    Storage::disk('local')->assertExists($log->attachment_path);

    // Le PDF persisté commence par %PDF
    $pdfContent = Storage::disk('local')->get($log->attachment_path);
    expect($pdfContent)->toStartWith('%PDF');

    // objet ne doit pas contenir de placeholders non substitués
    expect($log->objet)->not->toMatch('/\{[a-z_]+\}/');

    // catégorie correcte
    expect($log->categorie)->toBe(CategorieEmail::Document->value);
    expect($log->statut)->toBe('envoye');

    // envoye_par pointe vers l'utilisateur authentifié
    expect((int) $log->envoye_par)->toBe((int) $this->user->id);

    // participant_id correct
    expect((int) $log->participant_id)->toBe((int) $this->participant->id);
});

it('enregistre un EmailLog statut erreur quand Mail::send lève une exception depuis ParticipantShow', function (): void {
    // Force Mail to throw on send
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP connection refused'));

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->call('envoyerDocumentEmail', $this->doc->id);

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();
    expect($log->statut)->toBe('erreur');
    expect($log->erreur_message)->toBe('SMTP connection refused');
    expect($log->categorie)->toBe(CategorieEmail::Document->value);
    expect($log->destinataire_email)->toBe('jean@example.com');
    expect($log->attachment_path)->toBeNull();
    expect((int) $log->participant_id)->toBe((int) $this->participant->id);
});

it('n\'enregistre pas d\'EmailLog quand le tiers n\'a pas d\'email dans ParticipantShow', function (): void {
    $tiersNoEmail = Tiers::factory()->create([
        'email' => null,
        'prenom' => 'Marie',
        'nom' => 'MARTIN',
    ]);
    $participantNoEmail = Participant::create([
        'tiers_id' => $tiersNoEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    $docNoEmail = DocumentPrevisionnel::factory()->devis()->create([
        'operation_id' => $this->operation->id,
        'participant_id' => $participantNoEmail->id,
        'version' => 1,
        'date' => now()->toDateString(),
        'montant_total' => 100.00,
        'lignes_json' => [],
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $participantNoEmail,
    ])
        ->call('envoyerDocumentEmail', $docNoEmail->id);

    // Guard fires before try/catch — no EmailLog
    expect(EmailLog::query()->count())->toBe(0);
});
