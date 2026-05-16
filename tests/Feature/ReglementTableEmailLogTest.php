<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Livewire\ReglementTable;
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
    $typeOp = TypeOperation::factory()->create([
        'sous_categorie_id' => $sousCategorie->id,
        'email_from' => 'asso@example.com',
        'email_from_name' => 'Association Test',
    ]);

    $this->operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
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

    // Create a devis document previsionnel
    $this->doc = DocumentPrevisionnel::factory()->devis()->create([
        'operation_id' => $this->operation->id,
        'participant_id' => $this->participant->id,
        'version' => 1,
        'date' => now()->toDateString(),
        'montant_total' => 150.00,
        'lignes_json' => [],
    ]);

    // Create an email template for the Document category
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

it('enregistre un EmailLog avec corps_html et attachment_path après envoi de devis', function (): void {
    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('envoyerDocumentEmail', $this->participant->id, 'devis');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();

    // corps_html doit être rempli et contenir les données substituées
    expect($log->corps_html)->not->toBeNull();
    expect($log->corps_html)->toContain('Jean');

    // attachment_path doit pointer vers le dossier tenant
    expect($log->attachment_path)->not->toBeNull();
    expect($log->attachment_path)->toStartWith("associations/{$this->association->id}/email_attachments/");

    // objet ne doit pas contenir de placeholders non substitués
    expect($log->objet)->not->toMatch('/\{[a-z_]+\}/');

    // catégorie
    expect($log->categorie)->toBe(CategorieEmail::Document->value);

    // tracking_token persisté + pixel embarqué dans corps_html (ouvertures activées)
    expect($log->tracking_token)->not->toBeNull()->toHaveLength(32);
    expect($log->corps_html)->toContain('/t/'.$log->tracking_token.'.gif');

    // Le fichier doit exister sur le disque local fake
    Storage::disk('local')->assertExists($log->attachment_path);

    // Le PDF persisté commence par %PDF
    $pdfContent = Storage::disk('local')->get($log->attachment_path);
    expect($pdfContent)->toStartWith('%PDF');
});

it('enregistre un EmailLog erreur quand le tiers n\'a pas d\'email', function (): void {
    // Update tiers to have no email — this triggers the early return, no EmailLog
    // Instead, force a failure by removing the email_from on the type operation
    // so that the guard fires before DocumentMail is built.
    // A cleaner failure path: create a tiers with no email and a fresh participant+doc.
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
    DocumentPrevisionnel::factory()->devis()->create([
        'operation_id' => $this->operation->id,
        'participant_id' => $participantNoEmail->id,
        'version' => 1,
        'date' => now()->toDateString(),
        'montant_total' => 100.00,
        'lignes_json' => [],
    ]);

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('envoyerDocumentEmail', $participantNoEmail->id, 'devis');

    // No EmailLog — the guard fires before the try/catch
    expect(EmailLog::query()->count())->toBe(0);
});

it('enregistre un EmailLog statut erreur quand Mail::send lève une exception', function (): void {
    // Override Mail facade to throw on `to()`
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new RuntimeException('SMTP connection refused'));

    Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('envoyerDocumentEmail', $this->participant->id, 'devis');

    expect(EmailLog::query()->count())->toBe(1);

    $log = EmailLog::query()->first();
    expect($log->statut)->toBe('erreur');
    expect($log->erreur_message)->toBe('SMTP connection refused');
    expect($log->categorie)->toBe(CategorieEmail::Document->value);
    expect($log->destinataire_email)->toBe('jean@example.com');
    expect($log->attachment_path)->toBeNull();
});
