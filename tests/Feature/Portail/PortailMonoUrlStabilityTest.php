<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\AuthSessionService;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    // La seed globale (Pest.php) crée une asso par défaut.
    // On purge la table pour maîtriser le nombre d'assos dans chaque test.
    DB::table('association')->delete();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Mode mono — GET /portail/ sans auth → redirect vers /portail/login
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/ sans auth redirige vers /portail/login (pas de slug dans l\'URL)', function () {
    Association::factory()->create(['slug' => 'mon-association']);

    $this->get('/portail/')
        ->assertRedirect('/portail/login');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Mode mono — GET /portail/notes-de-frais sans auth → /portail/login
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/notes-de-frais sans auth redirige vers /portail/login', function () {
    Association::factory()->create(['slug' => 'mon-association']);

    $this->get('/portail/notes-de-frais')
        ->assertRedirect('/portail/login');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Mode mono — EnsureTiersChosen déclenché → /portail/choisir
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: EnsureTiersChosen redirige vers /portail/choisir (pas de slug)', function () {
    $asso = Association::factory()->create(['slug' => 'mon-association']);
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    // Marque une session avec deux tiers en attente → hasPendingChoice() = true
    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    // Non authentifié + pending choice → EnsureTiersChosen redirige
    $this->get('/portail/')
        ->assertRedirect('/portail/choisir');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Mode mono — LogoutController → /portail/login
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: POST /portail/logout redirige vers /portail/login (pas de slug)', function () {
    $asso = Association::factory()->create(['slug' => 'mon-association']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->post('/portail/logout')
        ->assertRedirect('/portail/login');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Mode multi — GET /svs/portail/ sans auth → /svs/portail/login
// ─────────────────────────────────────────────────────────────────────────────
it('mode multi: GET /svs/portail/ sans auth redirige vers /svs/portail/login', function () {
    Association::factory()->create(['slug' => 'svs']);
    Association::factory()->create(['slug' => 'autre']);

    $this->get('/svs/portail/')
        ->assertRedirect('/svs/portail/login');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Mode multi — GET /svs/portail/notes-de-frais sans auth → /svs/portail/login
// ─────────────────────────────────────────────────────────────────────────────
it('mode multi: GET /svs/portail/notes-de-frais sans auth redirige vers /svs/portail/login', function () {
    Association::factory()->create(['slug' => 'svs']);
    Association::factory()->create(['slug' => 'autre']);

    $this->get('/svs/portail/notes-de-frais')
        ->assertRedirect('/svs/portail/login');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Mode multi — LogoutController → /svs/portail/login
// ─────────────────────────────────────────────────────────────────────────────
it('mode multi: POST /svs/portail/logout redirige vers /svs/portail/login', function () {
    $asso = Association::factory()->create(['slug' => 'svs']);
    Association::factory()->create(['slug' => 'autre']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->post('/svs/portail/logout')
        ->assertRedirect('/svs/portail/login');
});
