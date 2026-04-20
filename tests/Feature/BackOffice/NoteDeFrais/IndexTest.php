<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutNoteDeFrais;
use App\Livewire\BackOffice\NoteDeFrais\Index;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

// ── Helpers ─────────────────────────────────────────────────────────────────

function ndfIndexMakeUserWithRole(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

// ── Authorization ────────────────────────────────────────────────────────────

it('returns 200 for an Admin', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = ndfIndexMakeUserWithRole($association, RoleAssociation::Admin);

    $this->actingAs($admin)
        ->get(route('comptabilite.ndf.index'))
        ->assertOk();
});

it('returns 200 for a Comptable', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $comptable = ndfIndexMakeUserWithRole($association, RoleAssociation::Comptable);

    $this->actingAs($comptable)
        ->get(route('comptabilite.ndf.index'))
        ->assertOk();
});

it('returns 403 for a Gestionnaire', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $gestionnaire = ndfIndexMakeUserWithRole($association, RoleAssociation::Gestionnaire);

    $this->actingAs($gestionnaire)
        ->get(route('comptabilite.ndf.index'))
        ->assertForbidden();
});

it('redirects to login when not authenticated', function (): void {
    $this->get(route('comptabilite.ndf.index'))
        ->assertRedirect(route('login'));
});

// ── Onglet par défaut ─────────────────────────────────────────────────────────

it('shows only Soumise NDF on the default a_traiter tab', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = ndfIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $soumise = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'NDF soumise visible',
    ]);

    $validee = NoteDeFrais::factory()->validee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'NDF validee invisible',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->assertSee('NDF soumise visible')
        ->assertDontSee('NDF validee invisible');
});

// ── Onglet validees ───────────────────────────────────────────────────────────

it('shows only Validee NDF on the validees tab', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = ndfIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    NoteDeFrais::factory()->validee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'NDF validee visible',
    ]);

    NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'NDF soumise invisible',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class, ['onglet' => 'validees'])
        ->set('onglet', 'validees')
        ->assertSee('NDF validee visible')
        ->assertDontSee('NDF soumise invisible');
});

// ── Onglet rejetees ──────────────────────────────────────────────────────────

it('shows only Rejetee NDF on the rejetees tab', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = ndfIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    NoteDeFrais::factory()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'statut' => StatutNoteDeFrais::Rejetee->value,
        'libelle' => 'NDF rejetee visible',
    ]);

    NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'NDF soumise invisible',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('onglet', 'rejetees')
        ->assertSee('NDF rejetee visible')
        ->assertDontSee('NDF soumise invisible');
});

// ── Onglet toutes ─────────────────────────────────────────────────────────────

it('shows Soumise + Validee + Rejetee on the toutes tab (not Brouillon)', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = ndfIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'NDF soumise toutes',
    ]);

    NoteDeFrais::factory()->validee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'libelle' => 'NDF validee toutes',
    ]);

    NoteDeFrais::factory()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'statut' => StatutNoteDeFrais::Rejetee->value,
        'libelle' => 'NDF rejetee toutes',
    ]);

    NoteDeFrais::factory()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'statut' => StatutNoteDeFrais::Brouillon->value,
        'libelle' => 'NDF brouillon invisible',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('onglet', 'toutes')
        ->assertSee('NDF soumise toutes')
        ->assertSee('NDF validee toutes')
        ->assertSee('NDF rejetee toutes')
        ->assertDontSee('NDF brouillon invisible');
});

// ── Brouillons jamais visibles ────────────────────────────────────────────────

it('never shows Brouillon NDF in back-office even on toutes tab', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = ndfIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    NoteDeFrais::factory()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'statut' => StatutNoteDeFrais::Brouillon->value,
        'libelle' => 'Brouillon confidentiel',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('onglet', 'toutes')
        ->assertDontSee('Brouillon confidentiel');

    Livewire::test(Index::class)
        ->assertDontSee('Brouillon confidentiel');
});

// ── Tri date décroissante ──────────────────────────────────────────────────────

it('orders NDF by date descending by default', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = ndfIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-01-01',
        'libelle' => 'NDF ancienne',
    ]);

    NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-06-01',
        'libelle' => 'NDF recente',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Index::class);

    $notes = $component->viewData('notes');

    expect($notes->first()->libelle)->toBe('NDF recente');
    expect($notes->last()->libelle)->toBe('NDF ancienne');
});

// ── Isolation tenant ──────────────────────────────────────────────────────────

it('does not show NDF from another association', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $admin = ndfIndexMakeUserWithRole($assocA, RoleAssociation::Admin);

    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);

    // We need to bypass TenantScope to create NDF for assocB
    TenantContext::clear();
    TenantContext::boot($assocB);
    NoteDeFrais::factory()->soumise()->create([
        'association_id' => $assocB->id,
        'tiers_id' => $tiersB->id,
        'libelle' => 'NDF asso B invisible',
    ]);

    // Switch back to assocA
    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->assertDontSee('NDF asso B invisible');
});
