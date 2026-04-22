<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * Verify that an already-authenticated user visiting any login URL
 * (plain, branded same-asso, branded other-asso) is redirected to
 * /dashboard without session disruption and without TenantContext
 * being switched to a foreign association.
 *
 * Step 6 of plan/slug-login-mono.md.
 */
beforeEach(function () {
    // Start from a clean slate so we control exactly which associations exist.
    Association::query()->forceDelete();
    TenantContext::clear();

    // Create SVS — Jean's home association.
    $this->svs = Association::factory()->create([
        'nom' => 'SVS',
        'slug' => 'svs',
    ]);

    // Create Exemple — a separate association Jean does NOT belong to.
    $this->exemple = Association::factory()->create([
        'nom' => 'Exemple',
        'slug' => 'exemple',
    ]);

    // Create Jean, member of SVS only.
    $this->jean = User::factory()->create(['email' => 'jean@svs.fr']);
    $this->jean->associations()->attach($this->svs->id, [
        'role' => 'membre',
        'joined_at' => now(),
    ]);
    $this->jean->update(['derniere_association_id' => $this->svs->id]);

    // Boot SVS as the active tenant (simulates what ResolveTenant does).
    TenantContext::boot($this->svs);
});

// ─────────────────────────────────────────────────────────────────────────────
// Case 1 — GET /login while authenticated
// ─────────────────────────────────────────────────────────────────────────────

test('authenticated user visiting GET /login is redirected to /dashboard', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $response = $this->get('/login');

    $response->assertStatus(302);
    $response->assertRedirect('/dashboard');
});

test('GET /login redirect keeps Jean session active', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $this->get('/login');

    $this->assertAuthenticatedAs($this->jean);
});

test('GET /login redirect does not change current_association_id in session', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $this->get('/login');

    $this->assertEquals((int) $this->svs->id, (int) session('current_association_id'));
});

// ─────────────────────────────────────────────────────────────────────────────
// Case 2 — GET /svs/login (same asso as session)
// ─────────────────────────────────────────────────────────────────────────────

test('authenticated user visiting GET /svs/login is redirected to /dashboard', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $response = $this->get('/svs/login');

    $response->assertStatus(302);
    $response->assertRedirect('/dashboard');
});

test('GET /svs/login redirect keeps Jean session active', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $this->get('/svs/login');

    $this->assertAuthenticatedAs($this->jean);
});

test('GET /svs/login redirect does not change current_association_id in session', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $this->get('/svs/login');

    $this->assertEquals((int) $this->svs->id, (int) session('current_association_id'));
});

// ─────────────────────────────────────────────────────────────────────────────
// Case 3 — GET /exemple/login (different asso — security-critical)
// ─────────────────────────────────────────────────────────────────────────────

test('authenticated user visiting GET /exemple/login is redirected to /dashboard', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $response = $this->get('/exemple/login');

    $response->assertStatus(302);
    $response->assertRedirect('/dashboard');
});

test('GET /exemple/login redirect keeps Jean session active', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $this->get('/exemple/login');

    $this->assertAuthenticatedAs($this->jean);
});

test('GET /exemple/login redirect does not change current_association_id to Exemple', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $this->get('/exemple/login');

    // The session must still show SVS — not switched to Exemple.
    $this->assertEquals((int) $this->svs->id, (int) session('current_association_id'));
});

test('GET /exemple/login does not switch TenantContext to Exemple association', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    // Boot SVS explicitly so we can verify it is NOT overridden.
    TenantContext::boot($this->svs);

    $this->get('/exemple/login');

    // TenantContext must remain SVS after the (redirected) request.
    expect(TenantContext::currentId())->toBe((int) $this->svs->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Case 4 — POST /svs/login while authenticated
// ─────────────────────────────────────────────────────────────────────────────

test('authenticated user POSTing to /svs/login is redirected by guest middleware', function () {
    $this->actingAs($this->jean);
    session(['current_association_id' => $this->svs->id]);

    $response = $this->post('/svs/login', [
        'email' => 'jean@svs.fr',
        'password' => 'password',
    ]);

    // guest middleware must intercept and redirect (302 to /dashboard).
    $response->assertStatus(302);
    $response->assertRedirect('/dashboard');
});
