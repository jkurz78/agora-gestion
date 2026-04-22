<?php

declare(strict_types=1);

use App\Models\Association;
use App\Tenant\TenantContext;

/**
 * Tests for the branded login route GET /{slug}/login (Step 4).
 *
 * The global Pest.php beforeEach creates a default association and boots
 * TenantContext. Our beforeEach overrides that to create a predictable "SVS"
 * association with a known slug, ensuring deterministic assertions.
 */
beforeEach(function () {
    // Replace the global-bootstrap association with one having a known slug.
    Association::query()->forceDelete();
    TenantContext::clear();

    Association::factory()->create([
        'nom' => 'SVS',
        'slug' => 'svs',
    ]);
});

test('GET /svs/login returns 200', function () {
    $response = $this->get('/svs/login');

    $response->assertStatus(200);
});

test('GET /svs/login h2 shows association name SVS not AgoraGestion', function () {
    $response = $this->get('/svs/login');

    $response->assertSee('<h2 class="mb-0">SVS</h2>', false);
    $response->assertDontSee('<h2 class="mb-0">AgoraGestion</h2>', false);
});

test('GET /svs/login view receives non-null $association with slug svs', function () {
    // We verify TenantContext is booted by checking the rendered output
    // contains the association name (injected via LayoutAssociationComposerProvider
    // which calls CurrentAssociation::tryGet() — only non-null when TenantContext is booted).
    $response = $this->get('/svs/login');

    $response->assertStatus(200);
    // The guest layout renders $nomAsso = $association?->nom ?? 'AgoraGestion'
    // If TenantContext is booted, $association->nom === 'SVS' will appear.
    $response->assertSee('SVS');
});

test('GET /inconnu/login returns 404 when no association has that slug', function () {
    $response = $this->get('/inconnu/login');

    $response->assertStatus(404);
});

test('GET /dashboard/login returns 404 when no association has slug dashboard', function () {
    // "dashboard" is a reserved slug — no association should carry it.
    // Since our beforeEach only creates slug=svs, /dashboard/login must 404.
    $response = $this->get('/dashboard/login');

    $response->assertStatus(404);
});
