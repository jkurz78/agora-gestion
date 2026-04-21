<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutNoteDeFrais;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;

// ── Helpers ───────────────────────────────────────────────────────────────────

function badgeMakeUserWithRole(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function badgeCreateSoumise(Association $association, int $count = 1): void
{
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    for ($i = 0; $i < $count; $i++) {
        NoteDeFrais::factory()->soumise()->create([
            'association_id' => $association->id,
            'tiers_id' => $tiers->id,
        ]);
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('shows Notes de frais nav item with badge count for Admin with pending NDF', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = badgeMakeUserWithRole($association, RoleAssociation::Admin);
    badgeCreateSoumise($association, 3);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('Notes de frais');
    $response->assertSee('>3<', false);
});

it('shows Notes de frais nav item with badge count for Comptable with pending NDF', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $comptable = badgeMakeUserWithRole($association, RoleAssociation::Comptable);
    badgeCreateSoumise($association, 2);

    $response = $this->actingAs($comptable)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('Notes de frais');
    $response->assertSee('>2<', false);
});

it('does not show Notes de frais nav item for Gestionnaire', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $gestionnaire = badgeMakeUserWithRole($association, RoleAssociation::Gestionnaire);
    badgeCreateSoumise($association, 3);

    $response = $this->actingAs($gestionnaire)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertDontSee('Notes de frais');
});

it('shows Notes de frais nav item without badge when no pending NDF for Admin', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = badgeMakeUserWithRole($association, RoleAssociation::Admin);
    // No NDF created

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('Notes de frais');
    // Badge span with count should not appear (count is 0)
    $response->assertDontSee('bg-warning text-dark">0', false);
});

it('scopes badge count to the current tenant', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    // Create 3 NDF for assocA
    TenantContext::clear();
    TenantContext::boot($assocA);
    badgeCreateSoumise($assocA, 3);

    // Create 5 NDF for assocB (bypass scope temporarily)
    TenantContext::clear();
    TenantContext::boot($assocB);
    badgeCreateSoumise($assocB, 5);

    // Act as admin of assocA
    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $admin = badgeMakeUserWithRole($assocA, RoleAssociation::Admin);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('>3<', false);
    $response->assertDontSee('>5<', false);
});

it('does not count Brouillon NDF in the badge', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = badgeMakeUserWithRole($association, RoleAssociation::Admin);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    // 1 soumise + 3 brouillons
    NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    NoteDeFrais::factory()->count(3)->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'statut' => StatutNoteDeFrais::Brouillon->value,
    ]);

    $response = $this->actingAs($admin)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertSee('>1<', false);
    $response->assertDontSee('>4<', false);
});

it('does not show Notes de frais nav item for Consultation role', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $consultation = badgeMakeUserWithRole($association, RoleAssociation::Consultation);
    badgeCreateSoumise($association, 2);

    $response = $this->actingAs($consultation)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertDontSee('Notes de frais');
});
