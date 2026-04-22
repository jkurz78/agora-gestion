<?php

declare(strict_types=1);

use App\Models\Association;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;

/**
 * Verify that /login displays product branding (AgoraGestion) and never
 * leaks an association-specific name or tenant-asset logo URL.
 *
 * In mono-association mode (1 asso in DB), /login auto-brands itself (Step 10).
 * Neutral branding only applies in multi-association mode (2+ asso).
 * This test seeds 2 associations to exercise the multi-association code path.
 */
beforeEach(function () {
    // Wipe the default asso created by the global bootstrap and re-create
    // two associations to force multi-association mode — neutral branding applies.
    Association::query()->forceDelete();
    MonoAssociation::flush();
    TenantContext::clear();

    // Two associations → multi mode → /login must show product branding.
    Association::factory()->create([
        'nom' => 'SVS',
        'slug' => 'svs-test',
    ]);
    Association::factory()->create([
        'nom' => 'Autre',
        'slug' => 'autre-test',
    ]);
});

afterEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
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
