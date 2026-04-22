<?php

declare(strict_types=1);

use App\Models\Association;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Mode mono, GET /portail/login → 200 avec nom/logo de l'asso unique
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/login retourne 200 avec nom de l\'asso', function () {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);

    $this->get('/portail/login')
        ->assertStatus(200)
        ->assertSeeText('SVS');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Mode mono, GET /portail/logo → 200 (logo binaire ou redirect)
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/logo retourne 200 ou redirect', function () {
    Association::factory()->create(['slug' => 'svs']);

    $response = $this->get('/portail/logo');

    // Logo absent → redirect vers le logo par défaut (302 ou 200 selon config)
    expect($response->status())->toBeIn([200, 301, 302]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Mode mono, GET /portail/otp → 200 (page OTP)
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/otp retourne 200 (avec email en session)', function () {
    Association::factory()->create(['slug' => 'svs']);
    session(['portail.pending_email' => 'test@example.org']);

    $this->get('/portail/otp')
        ->assertStatus(200);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Mode mono, route slug-first fonctionne toujours en parallèle
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /svs/portail/login retourne aussi 200 (slug-first en parallèle)', function () {
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);

    $this->get('/svs/portail/login')
        ->assertStatus(200);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Mode multi (2 assos), GET /portail/login → 404
// ─────────────────────────────────────────────────────────────────────────────
it('mode multi: GET /portail/login retourne 404', function () {
    Association::factory()->create(['slug' => 'asso-a']);
    Association::factory()->create(['slug' => 'asso-b']);

    $this->get('/portail/login')
        ->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Mode multi, GET /portail/logo → 404
// ─────────────────────────────────────────────────────────────────────────────
it('mode multi: GET /portail/logo retourne 404', function () {
    Association::factory()->create(['slug' => 'asso-a']);
    Association::factory()->create(['slug' => 'asso-b']);

    $this->get('/portail/logo')
        ->assertStatus(404);
});
