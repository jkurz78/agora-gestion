<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : GET /portail/{slug}/ non-auth → redirect login
// ─────────────────────────────────────────────────────────────────────────────
it('GET /portail/{slug}/ sans authentification redirige vers portail.login', function () {
    $asso = Association::factory()->create();

    $this->get("/portail/{$asso->slug}/")
        ->assertRedirect("/portail/{$asso->slug}/login");
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : GET /portail/{slug}/ authentifié → 200 + contenu attendu
// ─────────────────────────────────────────────────────────────────────────────
it('GET /portail/{slug}/ authentifié affiche nom asso, bienvenue et placeholder', function () {
    $asso = Association::factory()->create(['nom' => 'Les Amis du Quartier']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Marie',
        'nom' => 'Dupont',
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/portail/{$asso->slug}/")
        ->assertStatus(200)
        ->assertSeeText('Les Amis du Quartier')
        ->assertSeeText('Marie')
        ->assertSeeText('DUPONT')
        ->assertSeeText('Déconnexion')
        ->assertSee('notes de frais');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : POST /portail/{slug}/logout → purge session + redirect login
// ─────────────────────────────────────────────────────────────────────────────
it('POST /portail/{slug}/logout détruit la session et redirige vers portail.login', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    // Set session activity marker to verify it is cleared
    session(['portail.last_activity_at' => now()->timestamp]);

    expect(Auth::guard('tiers-portail')->check())->toBeTrue();

    $this->post("/portail/{$asso->slug}/logout")
        ->assertRedirect("/portail/{$asso->slug}/login");

    expect(Auth::guard('tiers-portail')->check())->toBeFalse();
    expect(session('portail.last_activity_at'))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Session expire après 61 min → redirect login
// ─────────────────────────────────────────────────────────────────────────────
it('session portail expire après 61 minutes d\'inactivité', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    // Freeze time at a fixed reference point
    $reference = Carbon::create(2026, 4, 19, 12, 0, 0);
    Carbon::setTestNow($reference);

    // Last activity was at the reference time
    session(['portail.last_activity_at' => $reference->timestamp]);

    // Advance time by 61 minutes — session should be expired
    Carbon::setTestNow($reference->copy()->addMinutes(61));

    $this->get("/portail/{$asso->slug}/")
        ->assertRedirect("/portail/{$asso->slug}/login");

    Carbon::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Session encore active à 59 min → 200
// ─────────────────────────────────────────────────────────────────────────────
it('session portail reste active après 59 minutes d\'inactivité', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    // Freeze time at a fixed reference point
    $reference = Carbon::create(2026, 4, 19, 12, 0, 0);
    Carbon::setTestNow($reference);

    // Last activity was at the reference time
    session(['portail.last_activity_at' => $reference->timestamp]);

    // Advance time by 59 minutes — session should still be active
    Carbon::setTestNow($reference->copy()->addMinutes(59));

    $this->get("/portail/{$asso->slug}/")
        ->assertStatus(200);

    Carbon::setTestNow();
});
