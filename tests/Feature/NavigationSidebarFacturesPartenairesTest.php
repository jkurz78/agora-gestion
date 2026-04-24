<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\Association;
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
    $response->assertSee('Factures à comptabiliser');
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
    $response->assertSee('Factures à comptabiliser');
    $response->assertSeeHtml(route('back-office.factures-partenaires.index'));
});

it('n\'affiche pas le lien Factures à comptabiliser pour un Gestionnaire', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $gestionnaire = navFpMakeUserWithRole($association, RoleAssociation::Gestionnaire);

    $response = $this->actingAs($gestionnaire)->get(route('comptabilite.transactions'));

    $response->assertOk();
    $response->assertDontSee('Factures à comptabiliser');
});
