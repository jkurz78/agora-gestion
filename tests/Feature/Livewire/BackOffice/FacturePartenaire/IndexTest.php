<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\StatutFactureDeposee;
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

// ── Test 13 : Comptabiliser — dépôt Soumise dispatche l'event ────────────────

it('comptabiliser dispatches open-transaction-form-from-depot-facture for a Soumise depot', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->call('comptabiliser', $depot->id)
        ->assertDispatched('open-transaction-form-from-depot-facture', depotId: $depot->id);
});

// ── Test 14 : Comptabiliser — cross-tenant → 404 ─────────────────────────────

it('comptabiliser on a depot from another tenant throws 404', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $admin = fpIndexMakeUserWithRole($assocA, RoleAssociation::Admin);

    // Create depot for tenant B
    TenantContext::clear();
    TenantContext::boot($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);
    $depotB = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $assocB->id,
        'tiers_id' => $tiersB->id,
    ]);
    $depotBId = $depotB->id;

    // Switch back to tenant A
    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->call('comptabiliser', $depotBId)
        ->assertStatus(404);
});

// ── Test 15 : Comptabiliser — statut Traitee → flash error, pas de dispatch ──

it('comptabiliser on a Traitee depot flashes an error and does not dispatch', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $depot = FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    // Verify flash error via direct instance call (session() visible this way)
    $test = Livewire::test(Index::class);
    $test->instance()->comptabiliser($depot->id);
    expect(session('error'))->not->toBeNull();

    // Verify no event dispatched via Livewire fluent harness
    Livewire::test(Index::class)
        ->call('comptabiliser', $depot->id)
        ->assertNotDispatched('open-transaction-form-from-depot-facture');
});

// ── Test 16 : Comptabiliser — statut Rejetee → flash error, pas de dispatch ──

it('comptabiliser on a Rejetee depot flashes an error and does not dispatch', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $depot = FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    // Verify flash error via direct instance call (session() visible this way)
    $test = Livewire::test(Index::class);
    $test->instance()->comptabiliser($depot->id);
    expect(session('error'))->not->toBeNull();

    // Verify no event dispatched via Livewire fluent harness
    Livewire::test(Index::class)
        ->call('comptabiliser', $depot->id)
        ->assertNotDispatched('open-transaction-form-from-depot-facture');
});

// ── Test 17 : Rejeter — flux complet ─────────────────────────────────────────

it('rejeter full flow: ouvrirRejet opens modal, confirmerRejet rejects depot', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Index::class)
        ->call('ouvrirRejet', $depot->id);

    expect($component->get('showRejectModal'))->toBeTrue();
    expect($component->get('depotIdToReject'))->toBe($depot->id);
    expect($component->get('motifRejet'))->toBe('');

    $component->instance()->motifRejet = 'PDF illisible';
    $component->instance()->confirmerRejet();

    expect(session('success'))->not->toBeNull();
    expect($component->instance()->showRejectModal)->toBeFalse();
    expect($component->instance()->depotIdToReject)->toBeNull();
    expect($component->instance()->motifRejet)->toBe('');

    $depot->refresh();
    expect($depot->statut)->toBe(StatutFactureDeposee::Rejetee);
    expect($depot->motif_rejet)->toBe('PDF illisible');
});

// ── Test 18 : Rejeter — motif vide → validation error ────────────────────────

it('confirmerRejet with empty motif returns a validation error', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Index::class)
        ->call('ouvrirRejet', $depot->id)
        ->set('motifRejet', '')
        ->call('confirmerRejet')
        ->assertHasErrors(['motifRejet']);

    // Modal still open
    expect($component->get('showRejectModal'))->toBeTrue();

    // Depot still Soumise
    $depot->refresh();
    expect($depot->statut)->toBe(StatutFactureDeposee::Soumise);
});

// ── Test 19 : Rejeter — cross-tenant → 404 ───────────────────────────────────

it('ouvrirRejet on a depot from another tenant throws 404', function (): void {
    $assocA = Association::factory()->create();
    $assocB = Association::factory()->create();

    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $admin = fpIndexMakeUserWithRole($assocA, RoleAssociation::Admin);

    // Create depot for tenant B
    TenantContext::clear();
    TenantContext::boot($assocB);
    $tiersB = Tiers::factory()->create(['association_id' => $assocB->id]);
    $depotB = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $assocB->id,
        'tiers_id' => $tiersB->id,
    ]);
    $depotBId = $depotB->id;

    // Switch back to tenant A
    TenantContext::clear();
    TenantContext::boot($assocA);
    session(['current_association_id' => $assocA->id]);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->call('ouvrirRejet', $depotBId)
        ->assertStatus(404);
});

// ── Test 20 : Rejeter — statut ≠ Soumise → flash error, modale pas ouverte ───

it('ouvrirRejet on a Traitee depot flashes error and does not open modal', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $depot = FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    $test = Livewire::test(Index::class);
    $test->instance()->ouvrirRejet($depot->id);

    expect(session('error'))->not->toBeNull();
    expect($test->instance()->showRejectModal)->toBeFalse();
});

// ── Test 21 : Fermer rejet ────────────────────────────────────────────────────

it('fermerRejet resets modal state', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Index::class)
        ->call('ouvrirRejet', $depot->id)
        ->set('motifRejet', 'test')
        ->call('fermerRejet');

    expect($component->get('showRejectModal'))->toBeFalse();
    expect($component->get('depotIdToReject'))->toBeNull();
    expect($component->get('motifRejet'))->toBe('');
});

// ── Test 22 : transaction-saved event rafraîchit la liste ────────────────────

it('re-renders and hides a newly-Traitee depot after transaction-saved event', function (): void {
    $association = Association::factory()->create();
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);

    $admin = fpIndexMakeUserWithRole($association, RoleAssociation::Admin);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);
    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'numero_facture' => 'FACT-TO-COMPTABILISE',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(Index::class)
        ->assertSee('FACT-TO-COMPTABILISE');

    // Simulate comptabilisation: flip depot to Traitee in DB
    $depot->update(['statut' => StatutFactureDeposee::Traitee->value]);

    // Dispatch the browser event emitted by TransactionForm::save()
    $component->dispatch('transaction-saved')
        ->assertDontSee('FACT-TO-COMPTABILISE');
});
