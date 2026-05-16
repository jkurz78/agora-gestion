<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Mode mono, Tiers authentifié GET /portail/ → 200 + Bonjour {prenom}
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: Tiers authentifié GET /portail/ retourne 200 avec Bonjour prenom', function () {
    $asso = Association::factory()->create(['slug' => 'svs']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Marie',
    ]);

    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get('/portail/')
        ->assertStatus(200)
        ->assertSeeText('Bonjour Marie');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Mode mono, Tiers authentifié GET /portail/mon-profil → 200 + champs locked
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: Tiers authentifié GET /portail/mon-profil retourne 200 avec labels champs locked', function () {
    $asso = Association::factory()->create(['slug' => 'svs', 'email' => 'contact@svs.fr']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Marie',
        'nom' => 'Dupont',
        'email' => 'marie@example.com',
    ]);

    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get('/portail/mon-profil')
        ->assertStatus(200)
        ->assertSeeText('Mon profil')
        ->assertSee('Nom')
        ->assertSee('Prénom')
        ->assertSee('Email');
});
