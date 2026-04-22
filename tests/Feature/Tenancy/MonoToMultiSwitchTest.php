<?php

declare(strict_types=1);

use App\Models\Association;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Step 12 — Bascule mono→multi immédiate (via observer).
 *
 * Verifies that creating a 2nd association automatically invalidates the
 * MonoAssociation::isActive() memo so that the NEXT HTTP request sees the
 * correct multi-association state without any manual flush() call.
 */
beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();
});

afterEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
});

it('reflects mono→multi switch on the next request without a manual flush', function () {
    // ── SETUP MONO ────────────────────────────────────────────────────────────
    Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);

    // 1. MonoAssociation is active when exactly 1 asso exists.
    MonoAssociation::flush(); // ensure fresh read
    expect(MonoAssociation::isActive())->toBeTrue();

    // 2. /portail/login works in mono mode.
    // Reset TenantContext before each HTTP call to simulate a fresh request
    // (in production, each request = fresh PHP-FPM process = clean TenantContext).
    TenantContext::clear();
    $this->get('/portail/login')->assertStatus(200);

    // ── CREATE 2nd ASSO (simulates super-admin action) ───────────────────────
    // NOTE: We do NOT call MonoAssociation::flush() here manually.
    // The observer must do it automatically on Association::create().
    Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);

    // ── VERIFY MULTI MODE ────────────────────────────────────────────────────
    // 3. After Eloquent creates the 2nd asso, the observer must have called
    //    flush() so isActive() re-reads from DB and returns false.
    expect(MonoAssociation::isActive())->toBeFalse();

    // Simulate start of a fresh HTTP request (TenantContext is empty at request boot).
    TenantContext::clear();

    // 4. /portail/login returns 404 in multi mode (RequireMono aborts).
    $this->get('/portail/login')->assertStatus(404);

    // Simulate start of another fresh HTTP request.
    TenantContext::clear();

    // 5. /login shows neutral AgoraGestion branding (no asso name in h2).
    $this->get('/login')
        ->assertStatus(200)
        ->assertSee('<h2 class="mb-0">AgoraGestion</h2>', false)
        ->assertDontSee('<h2 class="mb-0">SVS</h2>', false)
        ->assertDontSee('<h2 class="mb-0">Exemple</h2>', false);
});

it('handles mono→multi→mono cycle correctly', function () {
    // Start with 1 asso (mono).
    $svs = Association::factory()->create(['nom' => 'SVS', 'slug' => 'svs']);
    MonoAssociation::flush();
    expect(MonoAssociation::isActive())->toBeTrue();

    // Add a 2nd asso — observer flushes the cache.
    $exemple = Association::factory()->create(['nom' => 'Exemple', 'slug' => 'exemple']);
    expect(MonoAssociation::isActive())->toBeFalse();

    // Delete the 2nd asso — observer flushes again, mono returns.
    $exemple->delete();
    expect(MonoAssociation::isActive())->toBeTrue();
});
