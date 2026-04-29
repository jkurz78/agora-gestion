<?php

declare(strict_types=1);

use App\Livewire\OperationCommunication;
use App\Livewire\OperationDetail;
use App\Livewire\ParticipantShow;
use App\Mail\MessageLibreMail;
use App\Models\Association;
use App\Models\CampagneEmail;
use App\Models\EmailLog;
use App\Models\MessageTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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

it('mounts with operation and selects participants with email', function () {
    $tiersWithEmail = Tiers::factory()->create(['email' => 'alice@example.com', 'association_id' => $this->association->id]);
    $tiersNoEmail = Tiers::factory()->create(['email' => null, 'association_id' => $this->association->id]);

    $p1 = Participant::create([
        'tiers_id' => $tiersWithEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    Participant::create([
        'tiers_id' => $tiersNoEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $component->assertSet('selectedParticipants', [$p1->id]);
});

it('shows communication tab in operation detail', function () {
    $component = Livewire::test(OperationDetail::class, ['operation' => $this->operation]);

    $component->assertSee('Communication');
});

it('toggles select all participants', function () {
    $tiers1 = Tiers::factory()->create(['email' => 'a@example.com', 'association_id' => $this->association->id]);
    $tiers2 = Tiers::factory()->create(['email' => 'b@example.com', 'association_id' => $this->association->id]);

    $p1 = Participant::create([
        'tiers_id' => $tiers1->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    $p2 = Participant::create([
        'tiers_id' => $tiers2->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    // Starts with all selected (2 with email)
    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);
    $component->assertSet('selectedParticipants', [$p1->id, $p2->id]);

    // Toggle → deselect all
    $component->call('toggleSelectAll');
    $component->assertSet('selectedParticipants', []);

    // Toggle again → select all
    $component->call('toggleSelectAll');
    $selectedAfter = $component->get('selectedParticipants');
    expect($selectedAfter)->toContain($p1->id)->toContain($p2->id);
});

it('excludes participants without email from selection', function () {
    $tiersWithEmail = Tiers::factory()->create(['email' => 'ok@example.com', 'association_id' => $this->association->id]);
    $tiersNoEmail = Tiers::factory()->create(['email' => null, 'association_id' => $this->association->id]);

    $pWithEmail = Participant::create([
        'tiers_id' => $tiersWithEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    $pNoEmail = Participant::create([
        'tiers_id' => $tiersNoEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $selected = $component->get('selectedParticipants');
    expect($selected)->toContain($pWithEmail->id)
        ->not->toContain($pNoEmail->id);
});

it('loads template into objet and corps', function () {
    $template = MessageTemplate::create([
        'association_id' => $this->association->id,
        'nom' => 'Rappel séance',
        'objet' => 'Rappel : votre prochaine séance',
        'corps' => 'Bonjour {prenom}, à bientôt !',
        'type_operation_id' => null,
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $this->operation])
        ->set('selectedTemplateId', $template->id)
        ->call('loadTemplate')
        ->assertSet('objet', 'Rappel : votre prochaine séance')
        ->assertSet('corps', 'Bonjour {prenom}, à bientôt !');
});

// Step 9 tests

it('computes unresolved variables when no future seance exists', function () {
    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    // Set corps containing a variable that requires a future seance
    $component->set('corps', 'Prochaine séance : {date_prochaine_seance}');

    $instance = $component->instance();
    $unresolved = $instance->getUnresolvedVariables();

    expect($unresolved)->toContain('{date_prochaine_seance}');
});

it('returns empty unresolved when all variables are resolvable', function () {
    // Create a future seance for the operation
    Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => now()->addDays(7)->toDateString(),
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);
    $component->set('corps', 'Prochaine séance : {date_prochaine_seance}');

    $instance = $component->instance();
    $unresolved = $instance->getUnresolvedVariables();

    expect($unresolved)->toBeEmpty();
});

// Step 10 tests

it('saves a new message template', function () {
    Livewire::test(OperationCommunication::class, ['operation' => $this->operation])
        ->set('objet', 'Rappel séance')
        ->set('corps', 'Bonjour {prenom}')
        ->set('showSaveTemplate', true)
        ->set('templateNom', 'Mon modèle test')
        ->call('saveAsTemplate');

    $this->assertDatabaseHas('message_templates', [
        'nom' => 'Mon modèle test',
        'objet' => 'Rappel séance',
        'corps' => 'Bonjour {prenom}',
        'type_operation_id' => null,
    ]);
});

it('updates existing message template', function () {
    $template = MessageTemplate::create([
        'association_id' => $this->association->id,
        'nom' => 'Gabarit original',
        'objet' => 'Objet original',
        'corps' => 'Corps original',
        'type_operation_id' => null,
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $this->operation])
        ->set('selectedTemplateId', $template->id)
        ->call('loadTemplate')
        ->set('objet', 'Objet modifié')
        ->set('corps', 'Corps modifié')
        ->call('updateTemplate');

    $this->assertDatabaseHas('message_templates', [
        'id' => $template->id,
        'objet' => 'Objet modifié',
        'corps' => 'Corps modifié',
    ]);
});

it('groups templates by type operation', function () {
    $typeOp = TypeOperation::factory()->create([
        'nom' => 'Sophrologie',
        'association_id' => $this->association->id,
    ]);

    MessageTemplate::create([
        'association_id' => $this->association->id,
        'nom' => 'Gabarit global',
        'objet' => 'Sujet global',
        'corps' => 'Corps global',
        'type_operation_id' => null,
    ]);
    MessageTemplate::create([
        'association_id' => $this->association->id,
        'nom' => 'Gabarit sophrologie',
        'objet' => 'Sujet sophrologie',
        'corps' => 'Corps sophrologie',
        'type_operation_id' => $typeOp->id,
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $component->assertSee('Gabarit global')
        ->assertSee('Gabarit sophrologie');
});

// Step 11 tests

it('validates file attachment mime types', function () {
    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $badFile = UploadedFile::fake()->create('script.exe', 100, 'application/octet-stream');

    $component->set('emailAttachments', [$badFile])
        ->assertHasErrors(['emailAttachments.*']);
});

it('validates file attachment count does not exceed 5', function () {
    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $files = [];
    for ($i = 0; $i < 6; $i++) {
        $files[] = UploadedFile::fake()->create("file{$i}.pdf", 100, 'application/pdf');
    }

    $component->set('emailAttachments', $files)
        ->assertHasErrors(['emailAttachments']);
});

it('removes an attachment by index', function () {
    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $instance = $component->instance();
    $file0 = UploadedFile::fake()->create('a.pdf', 100, 'application/pdf');
    $file1 = UploadedFile::fake()->create('b.pdf', 100, 'application/pdf');
    $instance->emailAttachments = [$file0, $file1];
    $instance->removeAttachment(0);

    expect($instance->emailAttachments)->toHaveCount(1);
    expect($instance->emailAttachments[0]->getClientOriginalName())->toBe('b.pdf');
});

// Step 12 tests

it('sends test email to specified address', function () {
    Mail::fake();

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'from@asso.fr',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);

    $tiers = Tiers::factory()->create([
        'email' => 'alice@example.com',
        'prenom' => 'Alice',
        'nom' => 'Dupont',
        'association_id' => $this->association->id,
    ]);
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $operation])
        ->set('objet', 'Bonjour {prenom}')
        ->set('corps', 'Corps du message')
        ->set('testEmail', 'test@admin.fr')
        ->call('envoyerTest');

    Mail::assertSent(MessageLibreMail::class, fn ($mail) => $mail->hasTo('test@admin.fr'));
});

it('requires email_from configured for test send', function () {
    Mail::fake();

    $typeOp = TypeOperation::factory()->create([
        'email_from' => null,
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);

    $tiers = Tiers::factory()->create([
        'email' => 'alice@example.com',
        'association_id' => $this->association->id,
    ]);
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $operation])
        ->set('objet', 'Sujet')
        ->set('corps', 'Corps')
        ->set('testEmail', 'test@admin.fr')
        ->call('envoyerTest')
        ->assertSee("Adresse d'expédition non configurée");

    Mail::assertNothingSent();
});

it('requires selected destinataire for test send', function () {
    Mail::fake();

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'from@asso.fr',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $operation])
        ->set('objet', 'Sujet')
        ->set('corps', 'Corps')
        ->set('testEmail', 'test@admin.fr')
        ->set('selectedParticipants', [])
        ->call('envoyerTest')
        ->assertSee('Aucun destinataire sélectionné');

    Mail::assertNothingSent();
});

// Step 13 tests

it('sends emails to all selected participants and creates campaign', function () {
    Mail::fake();

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'from@asso.fr',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);

    $participants = [];
    for ($i = 1; $i <= 3; $i++) {
        $tiers = Tiers::factory()->create([
            'email' => "p{$i}@example.com",
            'association_id' => $this->association->id,
        ]);
        $participants[] = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => now(),
        ]);
    }

    $pIds = array_column($participants, 'id');

    Livewire::test(OperationCommunication::class, ['operation' => $operation])
        ->set('objet', 'Message de test')
        ->set('corps', 'Bonjour {prenom}')
        ->set('selectedParticipants', $pIds)
        ->call('envoyerMessages');

    Mail::assertSentCount(3);

    $campagne = CampagneEmail::first();
    expect($campagne)->not->toBeNull();
    expect($campagne->operation_id)->toBe($operation->id);
    expect($campagne->nb_erreurs)->toBe(0);

    expect(EmailLog::where('operation_id', $operation->id)->count())->toBe(3);
    expect(EmailLog::where('campagne_id', $campagne->id)->count())->toBe(3);
    expect(EmailLog::where('statut', 'envoye')->count())->toBe(3);
});

it('logs error and updates campaign nb_erreurs on send failure', function () {
    Mail::fake();
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new Exception('SMTP error'));

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'from@asso.fr',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);

    $tiers = Tiers::factory()->create([
        'email' => 'fail@example.com',
        'association_id' => $this->association->id,
    ]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $operation])
        ->set('objet', 'Sujet')
        ->set('corps', 'Corps')
        ->set('selectedParticipants', [$participant->id])
        ->call('envoyerMessages');

    expect(EmailLog::where('statut', 'erreur')->count())->toBe(1);
    expect(CampagneEmail::first()->nb_erreurs)->toBe(1);
});

it('resets form after successful send', function () {
    Mail::fake();

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'from@asso.fr',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);

    $tiers = Tiers::factory()->create([
        'email' => 'alice@example.com',
        'association_id' => $this->association->id,
    ]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $operation])
        ->set('objet', 'Un sujet')
        ->set('corps', 'Un corps')
        ->set('selectedParticipants', [$participant->id])
        ->call('envoyerMessages')
        ->assertSet('objet', '')
        ->assertSet('corps', '')
        ->assertSet('selectedTemplateId', null);
});

// Step 14 tests

it('shows campaign history when campaigns exist', function () {
    $campagne = CampagneEmail::create([
        'operation_id' => $this->operation->id,
        'objet' => 'Newsletter test',
        'corps' => 'Contenu test',
        'nb_destinataires' => 2,
        'nb_erreurs' => 0,
        'envoye_par' => $this->user->id,
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $this->operation])
        ->assertSee('Newsletter test')
        ->assertSee('2 envoyé(s)');
});

it('toggles expanded campaign detail', function () {
    $campagne = CampagneEmail::create([
        'operation_id' => $this->operation->id,
        'objet' => 'Sujet campagne',
        'corps' => 'Corps',
        'nb_destinataires' => 1,
        'nb_erreurs' => 0,
        'envoye_par' => $this->user->id,
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $component->assertSet('expandedCampagneId', null);

    $component->call('toggleCampagne', $campagne->id);
    $component->assertSet('expandedCampagneId', $campagne->id);

    $component->call('toggleCampagne', $campagne->id);
    $component->assertSet('expandedCampagneId', null);
});

// Step 15 tests

it('shows message email logs in participant timeline', function () {
    $tiers = Tiers::factory()->create(['email' => 'bob@example.com', 'association_id' => $this->association->id]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    EmailLog::create([
        'tiers_id' => $tiers->id,
        'participant_id' => $participant->id,
        'operation_id' => $this->operation->id,
        'categorie' => 'message',
        'destinataire_email' => 'bob@example.com',
        'destinataire_nom' => 'Bob Dupont',
        'objet' => 'Rappel séance du 01/05',
        'statut' => 'envoye',
        'envoye_par' => $this->user->id,
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $participant,
    ])
        ->assertSee('message')
        ->assertSee('bob@example.com');
});

// Step 16 tests

it('seeds default message templates', function () {
    $this->seed(MessageTemplateSeeder::class);
    expect(MessageTemplate::count())->toBeGreaterThanOrEqual(6);
    expect(MessageTemplate::where('nom', 'Rappel prochaine séance')->exists())->toBeTrue();
    expect(MessageTemplate::where('nom', 'Confirmation d\'inscription')->exists())->toBeTrue();
});

it('does not duplicate templates when seeded twice', function () {
    $this->seed(MessageTemplateSeeder::class);
    $countAfterFirst = MessageTemplate::count();

    $this->seed(MessageTemplateSeeder::class);
    expect(MessageTemplate::count())->toBe($countAfterFirst);
});

// ─── Encadrants (tiers from depense transactions on the operation) ──────────

/**
 * Helper: creates a depense transaction whose unique line is bound to the
 * given operation. The transaction's tiers becomes an "encadrant" for that
 * operation in the Communication tab.
 */
function makeEncadrantTransaction(int $associationId, int $operationId, Tiers $tiers, float $montant = 100.0): Transaction
{
    $transaction = Transaction::factory()->asDepense()->create([
        'association_id' => $associationId,
        'tiers_id' => $tiers->id,
        'date' => now(),
        'montant_total' => $montant,
    ]);

    TransactionLigne::where('transaction_id', $transaction->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'operation_id' => $operationId,
        'montant' => $montant,
    ]);

    return $transaction;
}

it('pre-selects encadrants with email at mount', function () {
    $encadrantWithEmail = Tiers::factory()->create(['email' => 'enc@example.com', 'association_id' => $this->association->id]);
    $encadrantNoEmail = Tiers::factory()->create(['email' => null, 'association_id' => $this->association->id]);

    makeEncadrantTransaction($this->association->id, $this->operation->id, $encadrantWithEmail);
    makeEncadrantTransaction($this->association->id, $this->operation->id, $encadrantNoEmail);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $selected = $component->get('selectedEncadrants');
    expect($selected)->toContain($encadrantWithEmail->id)
        ->not->toContain($encadrantNoEmail->id);
});

it('sends emails to selected encadrants alongside participants', function () {
    Mail::fake();

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'from@asso.fr',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);

    // 2 participants + 1 encadrant = 3 destinataires
    $participants = [];
    for ($i = 1; $i <= 2; $i++) {
        $tiers = Tiers::factory()->create([
            'email' => "p{$i}@example.com",
            'association_id' => $this->association->id,
        ]);
        $participants[] = Participant::create([
            'tiers_id' => $tiers->id,
            'operation_id' => $operation->id,
            'date_inscription' => now(),
        ]);
    }

    $encadrant = Tiers::factory()->create([
        'email' => 'encadrant@example.com',
        'association_id' => $this->association->id,
    ]);
    makeEncadrantTransaction($this->association->id, $operation->id, $encadrant);

    Livewire::test(OperationCommunication::class, ['operation' => $operation])
        ->set('objet', 'Test')
        ->set('corps', 'Bonjour {prenom}')
        ->call('envoyerMessages');

    Mail::assertSentCount(3);

    $campagne = CampagneEmail::first();
    expect($campagne->nb_destinataires)->toBe(3);
    expect($campagne->nb_erreurs)->toBe(0);

    // Encadrant gets a log row with participant_id null
    $encadrantLog = EmailLog::where('tiers_id', $encadrant->id)->first();
    expect($encadrantLog)->not->toBeNull();
    expect($encadrantLog->participant_id)->toBeNull();
});

it('toggleSelectAllEncadrants flips encadrant selection', function () {
    $e1 = Tiers::factory()->create(['email' => 'a@example.com', 'association_id' => $this->association->id]);
    $e2 = Tiers::factory()->create(['email' => 'b@example.com', 'association_id' => $this->association->id]);
    makeEncadrantTransaction($this->association->id, $this->operation->id, $e1);
    makeEncadrantTransaction($this->association->id, $this->operation->id, $e2);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);
    $component->call('toggleSelectAllEncadrants');
    expect($component->get('selectedEncadrants'))->toBe([]);

    $component->call('toggleSelectAllEncadrants');
    $selected = $component->get('selectedEncadrants');
    expect($selected)->toContain($e1->id)->toContain($e2->id);
});

it('test send works when only an encadrant is selected (no participant)', function () {
    Mail::fake();

    $typeOp = TypeOperation::factory()->create([
        'email_from' => 'from@asso.fr',
        'association_id' => $this->association->id,
    ]);
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'association_id' => $this->association->id,
    ]);

    $encadrant = Tiers::factory()->create([
        'email' => 'enc@example.com',
        'association_id' => $this->association->id,
        'prenom' => 'Marie',
        'nom' => 'Dupont',
    ]);
    makeEncadrantTransaction($this->association->id, $operation->id, $encadrant);

    Livewire::test(OperationCommunication::class, ['operation' => $operation])
        ->set('objet', 'Bonjour {prenom}')
        ->set('corps', 'Test')
        ->set('testEmail', 'test@admin.fr')
        ->set('selectedParticipants', [])
        ->call('envoyerTest');

    Mail::assertSentCount(1);
});
