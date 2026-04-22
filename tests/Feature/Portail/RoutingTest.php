<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Services\Portail\AuthSessionService;
use App\Tenant\TenantContext;

beforeEach(fn () => TenantContext::clear());

it('GET /{slug}/portail/login retourne 200', function () {
    $asso = Association::factory()->create();

    $this->get("/{$asso->slug}/portail/login")
        ->assertStatus(200);
});

it('GET /portail/slug-inconnu/login retourne 404', function () {
    $this->get('/portail/slug-qui-nexiste-pas/login')
        ->assertStatus(404);
});

it('GET /{slug}/portail/otp retourne 200 si email en session', function () {
    $asso = Association::factory()->create();

    session(['portail.pending_email' => 'test@example.org']);

    $this->get("/{$asso->slug}/portail/otp")
        ->assertStatus(200);
});

it('GET /{slug}/portail/otp redirige vers login si pas de session', function () {
    $asso = Association::factory()->create();

    $this->get("/{$asso->slug}/portail/otp")
        ->assertRedirect("/{$asso->slug}/portail/login");
});

it('GET /{slug}/portail/choisir avec pending retourne 200', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers1 = Tiers::factory()->create(['association_id' => $asso->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $asso->id]);

    $service = new AuthSessionService;
    $service->markPendingTiers([(int) $tiers1->id, (int) $tiers2->id]);

    $this->get("/{$asso->slug}/portail/choisir")
        ->assertStatus(200);
});

it('GET /{slug}/portail/ sans auth redirige vers login', function () {
    $asso = Association::factory()->create();

    $this->get("/{$asso->slug}/portail/")
        ->assertRedirect("/{$asso->slug}/portail/login");
});

it('le layout portail affiche le nom de l\'association', function () {
    $asso = Association::factory()->create(['nom' => 'Les Amis du Quartier']);

    $this->get("/{$asso->slug}/portail/login")
        ->assertStatus(200)
        ->assertSeeText('Les Amis du Quartier');
});
