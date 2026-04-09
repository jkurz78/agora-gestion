<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\IncomingMailAllowedSender;
use App\Models\IncomingMailParametres;

beforeEach(function () {
    if (Association::find(1) === null) {
        $assoc = new Association;
        $assoc->id = 1;
        $assoc->fill(['nom' => 'Test'])->save();
    }
});

it('exits success silently when no parametres row exists', function () {
    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('désactivé')
        ->assertSuccessful();
});

it('exits success silently when enabled is false', function () {
    IncomingMailParametres::create([
        'association_id' => 1,
        'enabled' => false,
    ]);

    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('désactivé')
        ->assertSuccessful();
});

it('exits failure when config is incomplete', function () {
    IncomingMailParametres::create([
        'association_id' => 1,
        'enabled' => true,
        'imap_host' => null,
    ]);
    IncomingMailAllowedSender::create([
        'association_id' => 1,
        'email' => 'copieur@test.fr',
    ]);

    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('incomplète')
        ->assertFailed();
});

it('exits failure when whitelist is empty', function () {
    IncomingMailParametres::create([
        'association_id' => 1,
        'enabled' => true,
        'imap_host' => 'mail.test.fr',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'imap_username' => 'user',
        'imap_password' => 'pass',
    ]);

    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('iste blanche')
        ->assertFailed();
});
