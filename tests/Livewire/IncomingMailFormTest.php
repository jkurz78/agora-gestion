<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Livewire\Parametres\IncomingMailForm;
use App\Models\Association;
use App\Models\IncomingMailAllowedSender;
use App\Models\IncomingMailParametres;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    if (Association::find(1) === null) {
        $assoc = new Association;
        $assoc->id = 1;
        $assoc->fill(['nom' => 'Test'])->save();
    }
    $this->admin = User::factory()->create(['role' => Role::Admin]);
});

it('loads existing parametres on mount', function () {
    IncomingMailParametres::create([
        'association_id' => 1,
        'enabled' => true,
        'imap_host' => 'mail.test.fr',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'imap_username' => 'user@test.fr',
        'imap_password' => 'secret',
        'processed_folder' => 'INBOX.Processed',
        'errors_folder' => 'INBOX.Errors',
        'max_per_run' => 50,
    ]);

    Livewire::actingAs($this->admin)
        ->test(IncomingMailForm::class)
        ->assertSet('enabled', true)
        ->assertSet('imapHost', 'mail.test.fr')
        ->assertSet('imapUsername', 'user@test.fr')
        ->assertSet('passwordDejaEnregistre', true)
        ->assertSet('imapPassword', '');
});

it('saves parametres', function () {
    Livewire::actingAs($this->admin)
        ->test(IncomingMailForm::class)
        ->set('imapHost', 'mail.test.fr')
        ->set('imapPort', 993)
        ->set('imapEncryption', 'ssl')
        ->set('imapUsername', 'user@test.fr')
        ->set('imapPassword', 'newsecret')
        ->call('sauvegarder')
        ->assertHasNoErrors();

    $row = IncomingMailParametres::where('association_id', 1)->first();
    expect($row)->not->toBeNull();
    expect($row->imap_host)->toBe('mail.test.fr');
    expect($row->imap_password)->toBe('newsecret');
});

it('rejects activation when whitelist is empty', function () {
    Livewire::actingAs($this->admin)
        ->test(IncomingMailForm::class)
        ->set('imapHost', 'mail.test.fr')
        ->set('imapPort', 993)
        ->set('imapEncryption', 'ssl')
        ->set('imapUsername', 'user@test.fr')
        ->set('imapPassword', 'secret')
        ->set('enabled', false)
        ->call('toggleEnabled')
        ->assertSet('enabled', false);

    // Aucune ligne de paramètres ne doit avoir été créée avec enabled=true
    expect(IncomingMailParametres::where('association_id', 1)->where('enabled', true)->count())->toBe(0);
});

it('allows activation when whitelist has at least one sender', function () {
    IncomingMailAllowedSender::create([
        'association_id' => 1,
        'email' => 'copieur@test.fr',
    ]);

    Livewire::actingAs($this->admin)
        ->test(IncomingMailForm::class)
        ->set('imapHost', 'mail.test.fr')
        ->set('imapPort', 993)
        ->set('imapEncryption', 'ssl')
        ->set('imapUsername', 'user@test.fr')
        ->set('imapPassword', 'secret')
        ->set('enabled', false)
        ->call('toggleEnabled')
        ->assertSet('enabled', true);
});

it('adds and removes allowed senders', function () {
    Livewire::actingAs($this->admin)
        ->test(IncomingMailForm::class)
        ->set('nouveauEmail', 'COPIEUR@test.fr')
        ->set('nouveauLabel', 'Copieur RdC')
        ->call('ajouterExpediteur')
        ->assertHasNoErrors()
        ->assertSet('nouveauEmail', '')
        ->assertSet('nouveauLabel', '');

    $row = IncomingMailAllowedSender::where('association_id', 1)->first();
    expect($row->email)->toBe('copieur@test.fr');
    expect($row->label)->toBe('Copieur RdC');

    Livewire::actingAs($this->admin)
        ->test(IncomingMailForm::class)
        ->call('supprimerExpediteur', $row->id);

    expect(IncomingMailAllowedSender::count())->toBe(0);
});

it('rejects duplicate sender email', function () {
    IncomingMailAllowedSender::create([
        'association_id' => 1,
        'email' => 'copieur@test.fr',
    ]);

    Livewire::actingAs($this->admin)
        ->test(IncomingMailForm::class)
        ->set('nouveauEmail', 'copieur@test.fr')
        ->call('ajouterExpediteur')
        ->assertHasErrors(['nouveauEmail']);
});
