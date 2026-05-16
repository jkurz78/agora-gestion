<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\EmailLog;
use App\Models\NoteDeFrais;
use App\Models\Participant;
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
        ->not->toContain('Factures')
        ->not->toContain('Historique dépenses');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 2 : Participant-NDF — pour_depenses=false mais ≥1 NDF existante
// Doit afficher "Tableau de bord", "Mon profil" et "Notes de frais".
// ─────────────────────────────────────────────────────────────────────────────
it('participant NDF affiche Notes de frais mais pas Factures ni Historique', function () {
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
        ->not->toContain('Factures')
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
        ->toContain('Factures')
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
        ->toContain('Factures')
        ->toContain('Historique dépenses');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 5 : Tiers avec ≥ 1 Participation → sidebar contient "Mes activités"
// ─────────────────────────────────────────────────────────────────────────────
it('tiers avec participation affiche Mes activités dans la sidebar', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => false,
    ]);

    Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);

    $html = view('portail.layouts.partials.sidebar', [
        'tiers' => $tiers,
        'portailAssociation' => $asso,
    ])->render();

    expect($html)->toContain('Mes activités');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 6 : Tiers sans Participation → sidebar NE contient PAS "Mes activités"
// ─────────────────────────────────────────────────────────────────────────────
it('tiers sans participation n\'affiche pas Mes activités dans la sidebar', function () {
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

    expect($html)->not->toContain('Mes activités');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 8 : Tiers avec ≥ 1 EmailLog → sidebar contient "Mes messages"
// ─────────────────────────────────────────────────────────────────────────────
it('tiers avec au moins un EmailLog affiche Mes messages dans la sidebar', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => false,
    ]);

    EmailLog::factory()->create(['tiers_id' => $tiers->id]);

    $html = view('portail.layouts.partials.sidebar', [
        'tiers' => $tiers,
        'portailAssociation' => $asso,
    ])->render();

    expect($html)->toContain('Mes messages');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 9 : Tiers sans EmailLog → sidebar NE contient PAS "Mes messages"
// ─────────────────────────────────────────────────────────────────────────────
it('tiers sans EmailLog n\'affiche pas Mes messages dans la sidebar', function () {
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

    expect($html)->not->toContain('Mes messages');
});

// ─────────────────────────────────────────────────────────────────────────────
// Scénario 7 : Anonyme (tiers=null) → collection vide, aucune section visible
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
        ->not->toContain('Factures')
        ->not->toContain('Historique dépenses');
});
