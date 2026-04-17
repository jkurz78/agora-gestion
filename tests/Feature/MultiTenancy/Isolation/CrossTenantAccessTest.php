<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenancy\Isolation;

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-tenant access intrusion tests.
 *
 * All scenarios verify that a user of tenant A cannot read or mutate
 * resources belonging to tenant B — via URL manipulation, POST, DELETE,
 * export, dashboard, session-switch, etc.
 */
final class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private Association $tenantA;

    private Association $tenantB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();

        $this->tenantA = Association::factory()->create(['nom' => 'Asso A']);
        $this->tenantB = Association::factory()->create(['nom' => 'Asso B']);

        $this->userA = User::factory()->create();
        $this->userA->associations()->attach($this->tenantA->id, ['role' => 'admin', 'joined_at' => now()]);
        $this->userA->update(['derniere_association_id' => $this->tenantA->id]);
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────

    private function actingAsUserA(): static
    {
        $this->actingAs($this->userA);
        session(['current_association_id' => $this->tenantA->id]);
        TenantContext::boot($this->tenantA);

        return $this;
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 1 — Operations list does not leak tenant B rows
    // ─────────────────────────────────────────────────────────

    public function test_user_a_does_not_see_operations_of_tenant_b(): void
    {
        TenantContext::boot($this->tenantB);
        Operation::factory()->create([
            'association_id' => $this->tenantB->id,
            'nom' => 'OP-SECRET-B',
        ]);
        TenantContext::clear();

        $this->actingAsUserA()
            ->get('/operations')
            ->assertOk()
            ->assertDontSee('OP-SECRET-B');
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 2 — Direct URL access to facture of tenant B returns 404
    // ─────────────────────────────────────────────────────────

    public function test_user_a_cannot_show_facture_of_tenant_b(): void
    {
        TenantContext::boot($this->tenantB);
        $tiersB = Tiers::factory()->create(['association_id' => $this->tenantB->id]);
        $factureB = Facture::create([
            'association_id' => $this->tenantB->id,
            'date' => now()->toDateString(),
            'tiers_id' => $tiersB->id,
            'saisi_par' => $this->userA->id,
            'exercice' => 2025,
            'statut' => 'brouillon',
        ]);
        TenantContext::clear();

        $this->actingAsUserA()
            ->get("/facturation/factures/{$factureB->id}")
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 3 — PATCH compte bancaire of tenant B returns 404 (no mutation)
    // ─────────────────────────────────────────────────────────

    public function test_user_a_cannot_update_compte_bancaire_of_tenant_b(): void
    {
        TenantContext::boot($this->tenantB);
        $compteB = CompteBancaire::factory()->create([
            'association_id' => $this->tenantB->id,
            'nom' => 'Compte-SECRET-B',
        ]);
        TenantContext::clear();

        $this->actingAsUserA()
            ->patch("/banques/comptes/{$compteB->id}", ['nom' => 'pirate'])
            ->assertNotFound();

        $this->assertDatabaseHas('comptes_bancaires', [
            'id' => $compteB->id,
            'nom' => 'Compte-SECRET-B',
        ]);
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 4 — DELETE compte bancaire of tenant B returns 404 (no deletion)
    // ─────────────────────────────────────────────────────────

    public function test_user_a_cannot_delete_compte_bancaire_of_tenant_b(): void
    {
        TenantContext::boot($this->tenantB);
        $compteB = CompteBancaire::factory()->create([
            'association_id' => $this->tenantB->id,
        ]);
        TenantContext::clear();

        $this->actingAsUserA()
            ->delete("/banques/comptes/{$compteB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('comptes_bancaires', ['id' => $compteB->id]);
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 5 — Edit page of compte bancaire of tenant B returns 404
    // ─────────────────────────────────────────────────────────

    public function test_user_a_cannot_view_compte_bancaire_edit_of_tenant_b(): void
    {
        TenantContext::boot($this->tenantB);
        $compteB = CompteBancaire::factory()->create([
            'association_id' => $this->tenantB->id,
        ]);
        TenantContext::clear();

        $this->actingAsUserA()
            ->get("/banques/comptes/{$compteB->id}/edit")
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 6 — Dashboard shows only tenant A metrics (not tenant B's 999)
    // ─────────────────────────────────────────────────────────

    public function test_dashboard_shows_only_tenant_a_metrics(): void
    {
        // Create 5 operations for tenant B — they must NOT appear on tenant A's dashboard.
        TenantContext::boot($this->tenantB);
        Operation::factory()->count(5)->create([
            'association_id' => $this->tenantB->id,
            'nom' => 'OP-TENANT-B-DASHBOARD',
        ]);
        TenantContext::clear();

        $this->actingAsUserA()
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('OP-TENANT-B-DASHBOARD');
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 7 — Analyse pivot page does not expose tenant B data
    // ─────────────────────────────────────────────────────────

    public function test_analyse_pivot_scopes_to_tenant_a(): void
    {
        TenantContext::boot($this->tenantB);
        // Create a tiers with a unique identifiable name; the AnalysePivot joins tiers.nom.
        Tiers::factory()->create([
            'association_id' => $this->tenantB->id,
            'nom' => 'FUITE-ANALYSE-B',
        ]);
        TenantContext::clear();

        $this->actingAsUserA()
            ->get('/rapports/analyse')
            ->assertOk()
            ->assertDontSee('FUITE-ANALYSE-B');
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 8 — CSV export of tiers does not leak tenant B records
    // ─────────────────────────────────────────────────────────

    public function test_export_tiers_does_not_leak_tenant_b(): void
    {
        TenantContext::boot($this->tenantB);
        Tiers::factory()->create([
            'association_id' => $this->tenantB->id,
            'nom' => 'FUITE-B',
        ]);
        TenantContext::clear();

        $response = $this->actingAsUserA()->get('/tiers/export?format=csv');
        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('FUITE-B', $content);
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 9 — User who injects another tenant's session_id cannot see that
    //              tenant's data (ResolveTenant blocks TenantContext from booting)
    // ─────────────────────────────────────────────────────────

    public function test_user_without_membership_cannot_see_injected_tenant_data(): void
    {
        // Create an identifiable operation in tenant A.
        TenantContext::boot($this->tenantA);
        Operation::factory()->create([
            'association_id' => $this->tenantA->id,
            'nom' => 'TENANTA-PRIVATE-OP',
        ]);
        TenantContext::clear();

        // foreignUser has NO membership on tenantA.
        $foreignUser = User::factory()->create();
        $foreignUser->update(['derniere_association_id' => $this->tenantA->id]);

        // Even though the session carries tenantA's ID, ResolveTenant detects
        // no membership and refuses to boot TenantContext — so the query scope
        // is never applied and the user sees NO tenant data.
        $this->actingAs($foreignUser)
            ->withSession(['current_association_id' => $this->tenantA->id])
            ->get('/operations')
            ->assertOk()
            ->assertDontSee('TENANTA-PRIVATE-OP');
    }

    // ─────────────────────────────────────────────────────────
    // Scenario 10 — Switching association reboots TenantContext
    // ─────────────────────────────────────────────────────────

    public function test_switching_association_reboots_tenant_context(): void
    {
        // userA is a member of both tenants.
        $this->userA->associations()->attach($this->tenantB->id, ['role' => 'admin', 'joined_at' => now()]);

        TenantContext::boot($this->tenantB);
        $opB = Operation::factory()->create([
            'association_id' => $this->tenantB->id,
            'nom' => 'OP-B',
        ]);
        TenantContext::clear();

        $this->actingAs($this->userA);

        // As tenant A → must NOT see OP-B.
        session(['current_association_id' => $this->tenantA->id]);
        $this->get('/operations')
            ->assertOk()
            ->assertDontSee('OP-B');

        // Switch to tenant B → MUST now see OP-B.
        $this->userA->update(['derniere_association_id' => $this->tenantB->id]);
        session(['current_association_id' => $this->tenantB->id]);
        $this->get('/operations')
            ->assertOk()
            ->assertSee('OP-B');
    }
}
