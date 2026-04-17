<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\IncomingMailAllowedSender;
use App\Models\IncomingMailParametres;

it('exits success silently when no parametres row exists', function () {
    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('aucun tenant')
        ->assertSuccessful();
});

it('exits success silently when enabled is false', function () {
    $asso = Association::factory()->create();

    IncomingMailParametres::create([
        'association_id' => $asso->id,
        'enabled' => false,
    ]);

    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('aucun tenant')
        ->assertSuccessful();
});

it('exits failure when config is incomplete', function () {
    $asso = Association::factory()->create(['slug' => 'test-asso']);

    IncomingMailParametres::create([
        'association_id' => $asso->id,
        'enabled' => true,
        'imap_host' => null,
    ]);
    IncomingMailAllowedSender::create([
        'association_id' => $asso->id,
        'email' => 'copieur@test.fr',
    ]);

    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('incomplète')
        ->assertFailed();
});

it('exits failure when whitelist is empty', function () {
    $asso = Association::factory()->create(['slug' => 'test-asso2']);

    IncomingMailParametres::create([
        'association_id' => $asso->id,
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
