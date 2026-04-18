<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\IncomingMailAllowedSender;
use App\Models\IncomingMailParametres;
use App\Tenant\TenantContext;

afterEach(function () {
    TenantContext::clear();
});

it('iterates all enabled tenants and skips disabled ones', function () {
    $tenantA = Association::factory()->create(['nom' => 'Tenant A', 'slug' => 'tenant-a']);
    $tenantB = Association::factory()->create(['nom' => 'Tenant B', 'slug' => 'tenant-b']);
    $tenantC = Association::factory()->create(['nom' => 'Tenant C', 'slug' => 'tenant-c']);

    // tenant-a: enabled, complete config, has whitelist → will attempt IMAP (and fail in test env)
    IncomingMailParametres::create(['association_id' => $tenantA->id, 'enabled' => true, 'imap_host' => 'imap.a.example', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => 'a', 'imap_password' => 'x', 'processed_folder' => 'Processed', 'errors_folder' => 'Errors', 'max_per_run' => 10]);
    // tenant-b: disabled → skipped entirely
    IncomingMailParametres::create(['association_id' => $tenantB->id, 'enabled' => false, 'imap_host' => 'imap.b.example', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => 'b', 'imap_password' => 'x', 'processed_folder' => 'Processed', 'errors_folder' => 'Errors', 'max_per_run' => 10]);
    // tenant-c: enabled but incomplete config → will output error and set hasFailure
    IncomingMailParametres::create(['association_id' => $tenantC->id, 'enabled' => true, 'imap_host' => null, 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_username' => null, 'imap_password' => null, 'processed_folder' => 'Processed', 'errors_folder' => 'Errors', 'max_per_run' => 10]);

    IncomingMailAllowedSender::create(['association_id' => $tenantA->id, 'email' => 'ok@a.example']);
    // tenant-c: no whitelist, but imap_host=null is caught first

    // The command iterates tenants; output must mention tenant-a and tenant-c but not tenant-b.
    // Exit code is FAILURE because tenant-c has incomplete config (hasFailure=true).
    $this->artisan('incoming-mail:fetch', ['--dry-run' => true])
        ->expectsOutputToContain('tenant-a')
        ->doesntExpectOutputToContain('tenant-b')
        ->expectsOutputToContain('tenant-c')
        ->run();

    // TenantContext must be cleared after the command regardless
    expect(TenantContext::currentId())->toBeNull();
});

it('returns success when all tenants succeed or none are enabled', function () {
    $this->artisan('incoming-mail:fetch')
        ->expectsOutputToContain('aucun tenant')
        ->assertSuccessful();
});
