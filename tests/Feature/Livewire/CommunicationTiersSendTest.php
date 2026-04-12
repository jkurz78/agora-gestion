<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Livewire\CommunicationTiers;
use App\Mail\CommunicationTiersMail;
use App\Models\Association;
use App\Models\CampagneEmail;
use App\Models\EmailLog;
use App\Models\MessageTemplate;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => Role::Admin]);
    $this->actingAs($this->admin);

    $assoc = Association::find(1) ?? new Association;
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Test Asso', 'email_from' => 'noreply@asso.fr', 'email_from_name' => 'Mon Asso'])->save();
});

// --- Templates ---

it('loads a message template', function () {
    $tpl = MessageTemplate::create([
        'categorie' => 'communication',
        'nom' => 'Convocation',
        'objet' => 'Convocation AG',
        'corps' => '<p>Cher {prenom}</p>',
        'type_operation_id' => null,
    ]);

    Livewire::test(CommunicationTiers::class)
        ->set('selectedTemplateId', $tpl->id)
        ->call('loadTemplate')
        ->assertSet('objet', 'Convocation AG')
        ->assertSet('corps', '<p>Cher {prenom}</p>');
});

it('saves a new message template', function () {
    Livewire::test(CommunicationTiers::class)
        ->set('objet', 'Test objet')
        ->set('corps', '<p>Test corps</p>')
        ->set('templateNom', 'Mon modèle')
        ->call('saveAsTemplate');

    $tpl = MessageTemplate::where('nom', 'Mon modèle')->first();
    expect($tpl)->not->toBeNull()
        ->and($tpl->categorie)->toBe('communication');
});

// --- Send ---

it('sends campaign to selected tiers', function () {
    Mail::fake();

    $t1 = Tiers::factory()->create(['nom' => 'A', 'prenom' => 'Jean', 'email' => 'a@e.com']);
    $t2 = Tiers::factory()->create(['nom' => 'B', 'prenom' => 'Paul', 'email' => 'b@e.com']);

    Livewire::test(CommunicationTiers::class)
        ->set('objet', 'Hello {prenom}')
        ->set('corps', '<p>Cher {prenom} {nom}</p>')
        ->set('selectedTiersIds', [$t1->id, $t2->id])
        ->call('envoyerMessages');

    Mail::assertSent(CommunicationTiersMail::class, 2);
    expect(CampagneEmail::whereNull('operation_id')->count())->toBe(1);
    expect(EmailLog::where('categorie', 'communication')->count())->toBe(2);
});

it('blocks send when no email_from configured', function () {
    Association::find(1)->update(['email_from' => null]);

    $tiers = Tiers::factory()->create(['email' => 'a@e.com']);

    Livewire::test(CommunicationTiers::class)
        ->set('objet', 'Test')
        ->set('corps', '<p>Test</p>')
        ->set('selectedTiersIds', [$tiers->id])
        ->call('envoyerMessages');

    expect(CampagneEmail::count())->toBe(0);
});

it('blocks send when no tiers selected', function () {
    Livewire::test(CommunicationTiers::class)
        ->set('objet', 'Test')
        ->set('corps', '<p>Test</p>')
        ->call('envoyerMessages');

    expect(CampagneEmail::count())->toBe(0);
});

it('creates email log with tracking token', function () {
    Mail::fake();

    $tiers = Tiers::factory()->create(['email' => 'tracked@e.com']);

    Livewire::test(CommunicationTiers::class)
        ->set('objet', 'Test')
        ->set('corps', '<p>Test</p>')
        ->set('selectedTiersIds', [$tiers->id])
        ->call('envoyerMessages');

    $log = EmailLog::where('destinataire_email', 'tracked@e.com')->first();
    expect($log)->not->toBeNull()
        ->and($log->tracking_token)->not->toBeNull()
        ->and($log->categorie)->toBe('communication')
        ->and($log->tiers_id)->toBe($tiers->id)
        ->and($log->operation_id)->toBeNull();
});

it('resets form after successful send', function () {
    Mail::fake();

    $tiers = Tiers::factory()->create(['email' => 'a@e.com']);

    Livewire::test(CommunicationTiers::class)
        ->set('objet', 'Test')
        ->set('corps', '<p>Test</p>')
        ->set('selectedTiersIds', [$tiers->id])
        ->call('envoyerMessages')
        ->assertSet('objet', '')
        ->assertSet('corps', '');
});

// --- Test send ---

it('sends test email', function () {
    Mail::fake();

    $tiers = Tiers::factory()->create(['email' => 'a@e.com', 'prenom' => 'Jean']);

    Livewire::test(CommunicationTiers::class)
        ->set('objet', 'Test {prenom}')
        ->set('corps', '<p>Hello</p>')
        ->set('selectedTiersIds', [$tiers->id])
        ->set('testEmail', 'dest@test.com')
        ->call('envoyerTest');

    Mail::assertSent(CommunicationTiersMail::class, function ($mail) {
        return $mail->hasTo('dest@test.com');
    });
});

// --- Campaign history ---

it('displays campaign history', function () {
    CampagneEmail::create([
        'operation_id' => null,
        'objet' => 'Passée',
        'corps' => '<p>Ancien</p>',
        'nb_destinataires' => 5,
        'nb_erreurs' => 0,
        'envoye_par' => $this->admin->id,
    ]);

    Livewire::test(CommunicationTiers::class)
        ->assertSee('Passée');
});

it('reuses a past campaign', function () {
    $campagne = CampagneEmail::create([
        'operation_id' => null,
        'objet' => 'Objet ancien',
        'corps' => '<p>Corps ancien</p>',
        'nb_destinataires' => 3,
        'nb_erreurs' => 0,
        'envoye_par' => $this->admin->id,
    ]);

    Livewire::test(CommunicationTiers::class)
        ->call('reutiliserCampagne', $campagne->id)
        ->assertSet('objet', 'Objet ancien')
        ->assertSet('corps', '<p>Corps ancien</p>');
});
