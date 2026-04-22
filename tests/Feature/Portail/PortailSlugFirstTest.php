<?php

declare(strict_types=1);

use App\Models\Association;
use App\Tenant\TenantContext;

beforeEach(fn () => TenantContext::clear());

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : nouvelle URL /{slug}/portail/login → 200
// ─────────────────────────────────────────────────────────────────────────────
it('GET /{slug}/portail/login retourne 200', function () {
    $asso = Association::factory()->create(['slug' => 'svs']);

    $this->get("/{$asso->slug}/portail/login")
        ->assertStatus(200);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : ancienne URL /portail/{slug}/login → 404
// ─────────────────────────────────────────────────────────────────────────────
it('GET /portail/{slug}/login retourne 404 (ancienne URL supprimée)', function () {
    $asso = Association::factory()->create(['slug' => 'svs']);

    $this->get("/portail/{$asso->slug}/login")
        ->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : route() helpers génèrent les bonnes URLs
// ─────────────────────────────────────────────────────────────────────────────
it('route portail.login génère /{slug}/portail/login', function () {
    expect(route('portail.login', ['association' => 'svs']))->toEndWith('/svs/portail/login');
});

it('route portail.home génère /{slug}/portail', function () {
    expect(route('portail.home', ['association' => 'svs']))->toEndWith('/svs/portail');
});

it('route portail.ndf.index génère /{slug}/portail/notes-de-frais', function () {
    expect(route('portail.ndf.index', ['association' => 'svs']))->toEndWith('/svs/portail/notes-de-frais');
});

it('route portail.logo génère /{slug}/portail/logo', function () {
    expect(route('portail.logo', ['association' => 'svs']))->toEndWith('/svs/portail/logo');
});
