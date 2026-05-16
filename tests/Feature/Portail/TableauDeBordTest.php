<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Tiers pour_depenses=false → 200, Bonjour {prenom}, 1 raccourci (Mon profil)
// ─────────────────────────────────────────────────────────────────────────────
it('tableau de bord membre seul affiche Bonjour prenom et 1 raccourci Mon profil', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Sophie',
        'pour_depenses' => false,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/{$asso->slug}/portail/")
        ->assertStatus(200)
        ->assertSeeText('Bonjour Sophie')
        ->assertSeeText('Mon profil')
        ->assertSee("/{$asso->slug}/portail/mon-profil")
        ->assertDontSeeText('Notes de frais')
        ->assertDontSeeText('Factures')
        ->assertDontSeeText('Historique dépenses');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Tiers pour_depenses=true → 200, 4 raccourcis (Mon profil + 3 dépenses)
// ─────────────────────────────────────────────────────────────────────────────
it('tableau de bord bénévole pour_depenses=true affiche 4 raccourcis', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'prenom' => 'Lucie',
        'pour_depenses' => true,
    ]);

    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/{$asso->slug}/portail/")
        ->assertStatus(200)
        ->assertSeeText('Bonjour Lucie')
        ->assertSeeText('Mon profil')
        ->assertSee("/{$asso->slug}/portail/mon-profil")
        ->assertSeeText('Notes de frais')
        ->assertSee("/{$asso->slug}/portail/notes-de-frais")
        ->assertSeeText('Factures')
        ->assertSeeText('Historique dépenses');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Anonyme → redirect vers portail.login
// ─────────────────────────────────────────────────────────────────────────────
it('tableau de bord anonyme redirige vers login', function () {
    $asso = Association::factory()->create();

    $this->get("/{$asso->slug}/portail/")
        ->assertRedirect("/{$asso->slug}/portail/login");
});
