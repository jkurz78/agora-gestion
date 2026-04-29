<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\IncomingMailParametres;
use App\Tenant\TenantContext;

afterEach(function () {
    TenantContext::clear();
    app()->detectEnvironment(fn (): string => 'testing');
});

it('skips IMAP fetch and returns exit code 0 in demo env without entering IMAP loop', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $association = Association::first() ?? Association::factory()->create(['nom' => 'Test Asso']);

    IncomingMailParametres::create([
        'association_id' => $association->id,
        'enabled' => true,
        'imap_host' => 'imap.example.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'imap_username' => 'user@example.com',
        'imap_password' => 'secret',
        'processed_folder' => 'Processed',
        'errors_folder' => 'Errors',
        'max_per_run' => 10,
    ]);

    // En env demo : doit retourner SUCCESS immédiatement sans entrer dans la boucle IMAP.
    // La preuve que la boucle est sautée : la sortie console NE contient PAS "démarrage ingestion".
    $this->artisan('incoming-mail:fetch')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('démarrage ingestion');
});

it('does enter IMAP loop in non-demo env (fails on IMAP connection, but loop was entered)', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $association = Association::first() ?? Association::factory()->create(['nom' => 'Test Asso']);

    IncomingMailParametres::create([
        'association_id' => $association->id,
        'enabled' => true,
        'imap_host' => 'imap.example.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'imap_username' => 'user@example.com',
        'imap_password' => 'secret',
        'processed_folder' => 'Processed',
        'errors_folder' => 'Errors',
        'max_per_run' => 10,
    ]);

    // En env local : la boucle IMAP est entrée (la sortie contient "démarrage ingestion").
    // La commande va échouer sur la connexion IMAP (pas de serveur disponible en test), c'est attendu.
    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('démarrage ingestion');
});
