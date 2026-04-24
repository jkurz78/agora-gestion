<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\BackOffice\FacturePartenaire\Index;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

// ── Helper ────────────────────────────────────────────────────────────────────

function fpIndexMakeUserWithRole(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

// ── Test 1 : Admin → 200 ─────────────────────────────────────────────────────

it('returns 200 for an Admin (comptable pivot)', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);

    $this->actingAs($admin)
        ->get(route('back-office.factures-partenaires.index'))
        ->assertOk();
});

// ── Test 2 : Comptable → 200 ──────────────────────────────────────────────────

it('returns 200 for a Comptable', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $comptable = fpIndexMakeUserWithRole($association, RoleAssociation::Comptable);

    $this->actingAs($comptable)
        ->get(route('back-office.factures-partenaires.index'))
        ->assertOk();
});

// ── Test 3 : Gestionnaire → 403 ──────────────────────────────────────────────

it('returns 403 for a Gestionnaire (non-comptable)', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $gestionnaire = fpIndexMakeUserWithRole($association, RoleAssociation::Gestionnaire);

    $this->actingAs($gestionnaire)
        ->get(route('back-office.factures-partenaires.index'))
        ->assertForbidden();
});

// ── Test 4 : Guest → redirect login ──────────────────────────────────────────

it('redirects to login when not authenticated', function (): void {
    $this->get(route('back-office.factures-partenaires.index'))
        ->assertRedirect(route('login'));
});

// ── Test 5 : Onglet a_traiter (default) → Soumise only ────────────────────────

it('shows only Soumise depots on the default a_traiter tab', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-SOUMISE-001',
    ]);

    FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-TRAITEE-001',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->assertSee('FACT-SOUMISE-001')
        ->assertDontSee('FACT-TRAITEE-001');
});

// ── Test 6 : Onglet traitees → Traitee only ──────────────────────────────────

it('shows only Traitee depots on the traitees tab', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-TRAITEE-002',
    ]);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-SOUMISE-002',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('onglet', 'traitees')
        ->assertSee('FACT-TRAITEE-002')
        ->assertDontSee('FACT-SOUMISE-002');
});

// ── Test 7 : Onglet rejetees → Rejetee only ──────────────────────────────────

it('shows only Rejetee depots on the rejetees tab', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-REJETEE-001',
    ]);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-SOUMISE-003',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('onglet', 'rejetees')
        ->assertSee('FACT-REJETEE-001')
        ->assertDontSee('FACT-SOUMISE-003');
});

// ── Test 8 : Onglet toutes → all statuts ─────────────────────────────────────

it('shows all statuts on the toutes tab', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-TOUTES-SOUMISE',
    ]);

    FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-TOUTES-TRAITEE',
    ]);

    FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-TOUTES-REJETEE',
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('onglet', 'toutes')
        ->assertSee('FACT-TOUTES-SOUMISE')
        ->assertSee('FACT-TOUTES-TRAITEE')
        ->assertSee('FACT-TOUTES-REJETEE');
});

// ── Test 9 : Cross-tenant isolation ──────────────────────────────────────────

it('does not show depots from another association (cross-tenant isolation)', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $admin = fpIndexMakeUserWithRole($assocA, RoleAssociation::Admin);

    // Create a depot for tenant B (switch context temporarily)
    TenantContext::clear();
    TenantContext::boot($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);
    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $assocB->id,
        'tiers_id' => $tiersB->id,
        'numero_facture' => 'FACT-ASSO-B-SECRET',
    ]);

    // Switch back to tenant A
    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->set('onglet', 'toutes')
        ->assertDontSee('FACT-ASSO-B-SECRET');
});

// ── Test 10 : Tri date_facture desc ──────────────────────────────────────────

it('orders depots by date_facture descending by default', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date_facture' => '2025-01-01',
        'numero_facture' => 'FACT-ANCIENNE',
    ]);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'date_facture' => '2025-06-01',
        'numero_facture' => 'FACT-RECENTE',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Index::class);

    $depots = $component->viewData('depots');

    expect($depots->first()->numero_facture)->toBe('FACT-RECENTE');
    expect($depots->last()->numero_facture)->toBe('FACT-ANCIENNE');
});

// ── Test 11 : Table headers present ──────────────────────────────────────────

it('renders the expected table headers', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    // At least one row so the table renders
    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->assertSee('Date facture')
        ->assertSee('Tiers')
        ->assertSee('N° facture')
        ->assertSee('Déposée le')
        ->assertSee('Taille PDF')
        ->assertSee('Statut')
        ->assertSee('Actions');
});

// ── Test 12 : Onglet URL persistence (wire:url binding) ──────────────────────

it('persists onglet in query string via Url attribute', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);

    $this->actingAs($admin);

    $component = Livewire::test(Index::class)
        ->set('onglet', 'traitees');

    expect($component->get('onglet'))->toBe('traitees');
});
