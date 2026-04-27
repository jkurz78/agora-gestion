<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

// ── Helpers ───────────────────────────────────────────────────────────────────

function bcSidebarAdminUser(Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

// ── beforeEach / afterEach ────────────────────────────────────────────────────

beforeEach(function (): void {
    TenantContext::clear();

    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);

    $this->admin = bcSidebarAdminUser($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ── Fix 2 : Breadcrumb "Comptabilité" sur /comptabilite/factures-fournisseurs ─

it('breadcrumb affiche "Comptabilité" sur la page Factures fournisseurs', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('comptabilite.factures-fournisseurs.index'));

    $response->assertOk();
    // The breadcrumb group "Comptabilité" must appear in the topbar
    $response->assertSee('Comptabilité');
    // The page title must be "Factures fournisseurs"
    $response->assertSee('Factures fournisseurs');
});

it('breadcrumb affiche "Comptabilité" sur la page Notes de frais', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('comptabilite.ndf.index'));

    $response->assertOk();
    $response->assertSee('Comptabilité');
    $response->assertSee('Notes de frais');
});

// ── Fix 3 : Sous-groupe "Réception" replié sur /dashboard ────────────────────

it('sous-groupe Réception est replié (aria-expanded=false) sur /dashboard', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('dashboard'));

    $response->assertOk();

    $html = $response->getContent();

    // aria-expanded="false" must appear in the Réception toggle (not "true")
    expect($html)->toContain('aria-expanded="false"');
    // The collapse div must NOT have class "show" next to sidebar-inbox-comptabilite
    // We verify the collapse div id is present without "show"
    expect($html)->toContain('id="sidebar-inbox-comptabilite"');
    // Verify no `collapse show` immediately precedes or contains sidebar-inbox-comptabilite
    expect($html)->not->toContain('collapse show" id="sidebar-inbox-comptabilite"');
});

// ── Fix 3 : Sous-groupe "Réception" ouvert sur /comptabilite/notes-de-frais ──

it('sous-groupe Réception est ouvert (aria-expanded=true) sur la page NDF', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('comptabilite.ndf.index'));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)->toContain('aria-expanded="true"');
    expect($html)->toContain('collapse show" id="sidebar-inbox-comptabilite"');
});

it('sous-groupe Réception est ouvert (aria-expanded=true) sur la page Factures fournisseurs', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('comptabilite.factures-fournisseurs.index'));

    $response->assertOk();

    $html = $response->getContent();

    expect($html)->toContain('aria-expanded="true"');
    expect($html)->toContain('collapse show" id="sidebar-inbox-comptabilite"');
});

// ── Fix 1 : Pas de H1 redondant sur les pages index ──────────────────────────

it('la page NDF index ne contient plus de balise h1 avec "Notes de frais"', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('comptabilite.ndf.index'));

    $response->assertOk();
    // The H1 wrapper must be gone; nav tab "Notes de frais" in breadcrumb/title is still fine
    $response->assertDontSee('<h1', false);
});

it('la page Factures fournisseurs index ne contient plus de balise h1', function (): void {
    $response = $this->actingAs($this->admin)
        ->get(route('comptabilite.factures-fournisseurs.index'));

    $response->assertOk();
    $response->assertDontSee('<h1', false);
});
