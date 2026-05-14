<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

// Tests d'accès à la route /{slug}/portail/notes-de-frais avec les 3 personas.
// Valide que EnsurePeutVoirNotesDeFrais (pas EnsurePourDepenses) est branché.

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 1 : pour_depenses=true, 0 NDF → 200
// ─────────────────────────────────────────────────────────────────────────────
it('route NDF: pour_depenses=true → 200', function () {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => true,
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200);
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 2 : pour_depenses=false, ≥1 NDF → 200 (nouveau comportement)
// C'est le RED test : échoue tant qu'EnsurePourDepenses est sur la route.
// ─────────────────────────────────────────────────────────────────────────────
it('route NDF: pour_depenses=false avec NDF existante → 200', function () {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => false,
    ]);
    NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $tiers->id,
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200);
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 3 : pour_depenses=false, 0 NDF → 302 redirect
// ─────────────────────────────────────────────────────────────────────────────
it('route NDF: pour_depenses=false sans NDF → redirect', function () {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => false,
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertRedirect();
});
