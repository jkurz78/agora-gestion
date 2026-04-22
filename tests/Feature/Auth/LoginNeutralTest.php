<?php

declare(strict_types=1);

use App\Models\Association;
use App\Tenant\TenantContext;

/**
 * Verify that /login displays product branding (AgoraGestion) and never
 * leaks an association-specific name or tenant-asset logo URL.
 *
 * The global Pest.php beforeEach already creates one association and boots
 * TenantContext. The guest layout is a public route — no tenant is booted —
 * so it must show product branding only.
 *
 * Bug reproduced: before the fix, Association::first() would return a real
 * asso and display its name in the <h2> instead of "AgoraGestion". We seed
 * an asso named "SVS" as the FIRST record (by clearing then re-creating) to
 * guarantee Association::first() returns it.
 */
beforeEach(function () {
    // Wipe the default asso created by the global bootstrap and re-create
    // "SVS" as the only — and therefore first — association in the DB.
    // This guarantees Association::first() === SVS (the bug case).
    Association::query()->forceDelete();
    TenantContext::clear();

    Association::factory()->create([
        'nom' => 'SVS',
        'slug' => 'svs-test',
    ]);
});

test('/login returns 200', function () {
    $response = $this->get('/login');
    $response->assertStatus(200);
});

test('/login h2 shows AgoraGestion not association name', function () {
    $response = $this->get('/login');

    // The <h2> in the guest layout must say "AgoraGestion", not the asso name.
    $response->assertSee('<h2 class="mb-0">AgoraGestion</h2>', false);
});

test('/login does not show association-specific name SVS in header', function () {
    $response = $this->get('/login');

    // The asso name "SVS" must not appear in the header h2.
    $response->assertDontSee('<h2 class="mb-0">SVS</h2>', false);
});

test('/login does not contain a tenant-asset logo URL', function () {
    $response = $this->get('/login');
    $response->assertDontSee('/tenant-assets/', false);
});
