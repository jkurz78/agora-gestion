<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Tenant\TenantContext;

beforeEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 1 : Membre seul — pour_depenses=false, 0 NDF
// Doit afficher uniquement "Tableau de bord" et "Mon profil".
// ─────────────────────────────────────────────────────────────────────────────
it('membre seul affiche Tableau de bord et Mon profil, pas les sections frais', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => false,
    ]);

    $html = view('portail.layouts.partials.sidebar', [
        'tiers' => $tiers,
        'portailAssociation' => $asso,
    ])->render();

    expect($html)
        ->toContain('Tableau de bord')
        ->toContain('Mon profil')
        ->not->toContain('Notes de frais')
        ->not->toContain('Factures partenaires')
        ->not->toContain('Historique dépenses');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 2 : Participant-NDF — pour_depenses=false mais ≥1 NDF existante
// Doit afficher "Tableau de bord", "Mon profil" et "Notes de frais".
// ─────────────────────────────────────────────────────────────────────────────
it('participant NDF affiche Notes de frais mais pas Factures partenaires ni Historique', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => false,
    ]);

    NoteDeFrais::factory()->create(['tiers_id' => $tiers->id]);

    $html = view('portail.layouts.partials.sidebar', [
        'tiers' => $tiers,
        'portailAssociation' => $asso,
    ])->render();

    expect($html)
        ->toContain('Tableau de bord')
        ->toContain('Mon profil')
        ->toContain('Notes de frais')
        ->not->toContain('Factures partenaires')
        ->not->toContain('Historique dépenses');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 3 : Bénévole — pour_depenses=true, 0 NDF
// Doit afficher les 5 entrées (toutes les sections).
// ─────────────────────────────────────────────────────────────────────────────
it('bénévole pour_depenses=true affiche toutes les 5 sections', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => true,
    ]);

    $html = view('portail.layouts.partials.sidebar', [
        'tiers' => $tiers,
        'portailAssociation' => $asso,
    ])->render();

    expect($html)
        ->toContain('Tableau de bord')
        ->toContain('Mon profil')
        ->toContain('Notes de frais')
        ->toContain('Factures partenaires')
        ->toContain('Historique dépenses');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 4 : Cumul — pour_depenses=true ET ≥1 NDF (même résultat que bénévole)
// ─────────────────────────────────────────────────────────────────────────────
it('cumul pour_depenses=true avec NDF affiche toutes les 5 sections', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => true,
    ]);

    NoteDeFrais::factory()->create(['tiers_id' => $tiers->id]);

    $html = view('portail.layouts.partials.sidebar', [
        'tiers' => $tiers,
        'portailAssociation' => $asso,
    ])->render();

    expect($html)
        ->toContain('Tableau de bord')
        ->toContain('Mon profil')
        ->toContain('Notes de frais')
        ->toContain('Factures partenaires')
        ->toContain('Historique dépenses');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 5 : Anonyme (tiers=null) → collection vide, aucune section visible
// ─────────────────────────────────────────────────────────────────────────────
it('tiers null produit une sidebar sans entrées de navigation', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $html = view('portail.layouts.partials.sidebar', [
        'tiers' => null,
        'portailAssociation' => $asso,
    ])->render();

    expect($html)
        ->not->toContain('Tableau de bord')
        ->not->toContain('Mon profil')
        ->not->toContain('Notes de frais')
        ->not->toContain('Factures partenaires')
        ->not->toContain('Historique dépenses');
});
