<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\AuthSessionService;
use App\Tenant\TenantContext;

beforeEach(fn () => TenantContext::clear());

it('GET /portail/{slug}/login retourne 200', function () {
    $asso = Association::factory()->create();

    $this->get("/portail/{$asso->slug}/login")
        ->assertStatus(200);
});

it('GET /portail/slug-inconnu/login retourne 404', function () {
    $this->get('/portail/slug-qui-nexiste-pas/login')
        ->assertStatus(404);
});

it('GET /portail/{slug}/otp retourne 200 si email en session', function () {
    $asso = Association::factory()->create();

    session(['portail.pending_email' => 'test@example.org']);

    $this->get("/portail/{$asso->slug}/otp")
        ->assertStatus(200);
});

it('GET /portail/{slug}/otp redirige vers login si pas de session', function () {
    $asso = Association::factory()->create();

    $this->get("/portail/{$asso->slug}/otp")
        ->assertRedirect("/portail/{$asso->slug}/login");
});

it('GET /portail/{slug}/choisir avec pending retourne 200', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    $this->get("/portail/{$asso->slug}/choisir")
        ->assertStatus(200);
});

it('GET /portail/{slug}/ sans auth redirige vers login', function () {
    $asso = Association::factory()->create();

    $this->get("/portail/{$asso->slug}/")
        ->assertRedirect("/portail/{$asso->slug}/login");
});

it('le layout portail affiche le nom de l\'association', function () {
    $asso = Association::factory()->create(['nom' => 'Les Amis du Quartier']);

    $this->get("/portail/{$asso->slug}/login")
        ->assertStatus(200)
        ->assertSeeText('Les Amis du Quartier');
});
