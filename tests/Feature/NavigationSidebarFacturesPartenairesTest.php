<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;

// ── Helpers ───────────────────────────────────────────────────────────────────

function navFpMakeUserWithRole(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('affiche le lien Factures à comptabiliser pour un Admin', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = navFpMakeUserWithRole($association, RoleAssociation::Admin);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('Boîte de réception');
    $response->assertSee('Factures');
    $response->assertSeeHtml(route('back-office.factures-partenaires.index'));
});

it('affiche le lien Factures à comptabiliser pour un Comptable', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $comptable = navFpMakeUserWithRole($association, RoleAssociation::Comptable);

    $response = $this->actingAs($comptable)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('Boîte de réception');
    $response->assertSee('Factures');
    $response->assertSeeHtml(route('back-office.factures-partenaires.index'));
});

it('affiche le badge compteur quand des factures Soumise existent', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = navFpMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('Factures');
    // Badge with count 1
    $response->assertSeeHtml('<span class="badge bg-warning text-dark ms-1">1</span>');
});

it('n\'affiche pas le badge quand aucune facture Soumise', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = navFpMakeUserWithRole($association, RoleAssociation::Admin);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));

    $response->assertOk();
    // No badge (no Soumise depots)
    $response->assertDontSee('badge bg-warning text-dark ms-1">1', false);
});

it('affiche le badge agrégé sur "Boîte de réception" quand NDF + Factures ont des items en attente', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = navFpMakeUserWithRole($association, RoleAssociation::Admin);

    // Create 3 pending factures partenaires (Soumise)
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    FacturePartenaireDeposee::factory()->soumise()->count(3)->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));

    $response->assertOk();
    // The aggregated badge on "Boîte de réception" shows total pending count (3 factures)
    $response->assertSeeInOrder(['Boîte de réception', '3']);
    // Individual badge on Factures item also present
    $response->assertSeeHtml('<span class="badge bg-warning text-dark ms-1">3</span>');
});

it('n\'affiche pas le lien Factures à comptabiliser pour un Gestionnaire', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $gestionnaire = navFpMakeUserWithRole($association, RoleAssociation::Gestionnaire);

    $response = $this->actingAs($gestionnaire)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertDontSeeHtml(route('back-office.factures-partenaires.index'));
});
