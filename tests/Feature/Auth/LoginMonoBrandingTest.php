<?php

declare(strict_types=1);

use App\Models\Association;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;

/**
 * Verify that /login auto-brands itself in mono-association mode (Step 10).
 *
 * When exactly 1 association exists, the MonoAssociationResolver middleware
 * must boot TenantContext so that layouts/guest.blade.php renders the asso's
 * name and logo instead of the generic AgoraGestion product branding.
 *
 * When 2+ associations exist, /login remains neutral.
 */
beforeEach(function () {
    // Wipe the global-bootstrap association and clear any cached state.
    Association::query()->forceDelete();
    MonoAssociation::flush();
    TenantContext::clear();
});

afterEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
});

test('GIVEN 1 asso SVS WHEN GET /login THEN html contains SVS and association is not null', function () {
    // GIVEN exactly 1 association in DB.
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);

    // WHEN
    $response = $this->get('/login');

    // THEN
    $response->assertStatus(200);
    // The guest layout renders $nomAsso = $association?->nom ?? 'AgoraGestion'.
    // With MonoAssociationResolver applied, $association is non-null → SVS appears.
    $response->assertSee('<h2 class="mb-0">SVS</h2>', false);
    $response->assertDontSee('<h2 class="mb-0">AgoraGestion</h2>', false);
});

test('GIVEN 2 assos WHEN GET /login THEN html shows AgoraGestion neutral branding', function () {
    // GIVEN 2 associations in DB → multi mode → neutral branding.
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    // WHEN
    $response = $this->get('/login');

    // THEN neutral branding: AgoraGestion shown, neither asso name in the h2.
    $response->assertStatus(200);
    $response->assertSee('<h2 class="mb-0">AgoraGestion</h2>', false);
    $response->assertDontSee('<h2 class="mb-0">SVS</h2>', false);
    $response->assertDontSee('<h2 class="mb-0">Exemple</h2>', false);
});

test('GIVEN 1 asso SVS WHEN GET /svs/login THEN html contains SVS (branded route unchanged)', function () {
    // GIVEN exactly 1 association in DB.
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);

    // WHEN accessing the slug-first branded route.
    $response = $this->get('/svs/login');

    // THEN the branded route still shows SVS branding.
    $response->assertStatus(200);
    $response->assertSee('<h2 class="mb-0">SVS</h2>', false);
});
