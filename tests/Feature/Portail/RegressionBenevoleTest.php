<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Smoke tests : les 6 écrans post-auth du portail bénévole utilisent
 * le nouveau layout portail.layouts.authenticated (sidebar présente).
 *
 * Ces tests ÉCHOUENT avant la migration (portail.layouts.app n'a pas de sidebar).
 * Ils passent en GREEN après que les 6 composants ont été basculés.
 */
beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => true,
    ]);
    Auth::guard('tiers-portail')->login($this->tiers);
    session(['portail.last_activity_at' => now()->timestamp]);
});

// ---------------------------------------------------------------------------
// Helper : élément sidebar présent dans la réponse
// ---------------------------------------------------------------------------
// "Se déconnecter" est dans sidebar.blade.php mais aussi dans app.blade.php
// On cible "Tableau de bord" qui ne figure que dans portail.layouts.authenticated
// via PortailSectionsResolver → TableauDeBordProvider.
// ---------------------------------------------------------------------------

it('ndf.index : 200 + sidebar présente', function () {
    $this->get("/{$this->asso->slug}/portail/notes-de-frais")
        ->assertStatus(200)
        ->assertSee('Tableau de bord');
});

it('ndf.create : 200 + sidebar présente', function () {
    $this->get("/{$this->asso->slug}/portail/notes-de-frais/nouvelle")
        ->assertStatus(200)
        ->assertSee('Tableau de bord');
});

it('ndf.show : 200 + sidebar présente', function () {
    $ndf = NoteDeFrais::factory()->brouillon()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
    ]);

    $this->get("/{$this->asso->slug}/portail/notes-de-frais/{$ndf->id}")
        ->assertStatus(200)
        ->assertSee('Tableau de bord');
});

it('factures.index : 200 + sidebar présente', function () {
    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertSee('Tableau de bord');
});

it('factures.create : 200 + sidebar présente', function () {
    $this->get("/{$this->asso->slug}/portail/factures/depot")
        ->assertStatus(200)
        ->assertSee('Tableau de bord');
});

it('historique.index : 200 + sidebar présente', function () {
    $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->assertSee('Tableau de bord');
});
