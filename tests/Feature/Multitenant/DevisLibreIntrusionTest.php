<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Livewire\DevisLibre\DevisEdit;
use App\Livewire\DevisLibre\DevisList;
use App\Models\Association;
use App\Models\Devis;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/**
 * Multi-tenant intrusion tests for the Devis libre module.
 *
 * Verifies that TenantScope (fail-closed) prevents any cross-tenant data access:
 *   - list (DevisList)
 *   - detail / edit page (DevisEdit)
 *   - PDF generation (DevisService::genererPdf)
 *   - email sending (DevisService::envoyerEmail)
 *   - duplication (DevisService::dupliquer)
 *   - vue 360° aggregation (TiersQuickViewService via DevisList filter)
 */
beforeEach(function () {
    TenantContext::clear();

    // ── Asso A (owner of the devis) ──────────────────────────────────────────
    $this->assoA = Association::factory()->create();
    $this->userA = User::factory()->create();
    $this->userA->associations()->attach($this->assoA->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($this->assoA);
    $this->tiersA = Tiers::factory()->create(['association_id' => $this->assoA->id]);
    $this->devisA = Devis::factory()->create([
        'association_id' => $this->assoA->id,
        'tiers_id' => $this->tiersA->id,
        'statut' => StatutDevis::Valide,
        'numero' => 'D-2026-014',
        'libelle' => 'Devis secret asso A',
    ]);

    // ── Asso B (intruder) ────────────────────────────────────────────────────
    $this->assoB = Association::factory()->create();
    $this->userB = User::factory()->create();
    $this->userB->associations()->attach($this->assoB->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ── DevisList: user B cannot see asso A's devis ──────────────────────────────

it('DevisList does not show asso A devis to asso B user', function () {
    // Boot asso B context
    TenantContext::boot($this->assoB);
    session(['current_association_id' => $this->assoB->id]);
    $this->actingAs($this->userB);

    Livewire::test(DevisList::class)
        ->assertDontSee('Devis secret asso A')
        ->assertDontSee('D-2026-014');
});

it('DevisList shows 0 devis to asso B user when asso A has devis', function () {
    // Boot asso B context — B has no devis of its own
    TenantContext::boot($this->assoB);
    session(['current_association_id' => $this->assoB->id]);
    $this->actingAs($this->userB);

    $component = Livewire::test(DevisList::class);
    $devis = $component->viewData('devis');
    expect($devis->total())->toBe(0);
});

// ── DevisEdit: user B cannot open asso A's devis ────────────────────────────

it('DevisEdit throws ModelNotFoundException when asso B user tries to access asso A devis', function () {
    // Boot asso B context
    TenantContext::boot($this->assoB);
    session(['current_association_id' => $this->assoB->id]);
    $this->actingAs($this->userB);

    // The TenantScope (fail-closed) causes Livewire model binding to fail:
    // Devis::find($this->devisA->id) returns null under asso B context.
    // Livewire raises ModelNotFoundException → 404.
    $this->get(route('devis-libres.show', ['devis' => $this->devisA->id]))
        ->assertStatus(404);
});

// ── DevisService::genererPdf: scope prevents loading cross-tenant devis ──────

it('DevisService::genererPdf cannot be called on asso A devis from asso B context', function () {
    // Pre-fetch devisA in asso A's context (scoped)
    TenantContext::boot($this->assoA);
    $devisA = Devis::find($this->devisA->id);
    expect($devisA)->not->toBeNull();

    // Switch to asso B context
    TenantContext::clear();
    TenantContext::boot($this->assoB);
    session(['current_association_id' => $this->assoB->id]);
    $this->actingAs($this->userB);

    // Calling genererPdf with asso A's devis from asso B context must throw:
    // guardAssociation() checks association_id against TenantContext::currentId()
    expect(fn () => app(DevisService::class)->genererPdf($devisA))
        ->toThrow(RuntimeException::class, 'Accès interdit');
});

// ── DevisService::envoyerEmail: scope prevents cross-tenant email ─────────────

it('DevisService::envoyerEmail cannot reach asso A devis from asso B context', function () {
    // Pre-fetch devisA in asso A's context (scoped)
    TenantContext::boot($this->assoA);
    $devisA = Devis::find($this->devisA->id);
    expect($devisA)->not->toBeNull();

    // Switch to asso B context
    TenantContext::clear();
    TenantContext::boot($this->assoB);
    session(['current_association_id' => $this->assoB->id]);
    $this->actingAs($this->userB);

    // The scope-level protection: Devis::find() returns null under B's context.
    $devisUnderB = Devis::find($this->devisA->id);
    expect($devisUnderB)->toBeNull();

    // Direct service call with asso A's devis from asso B context must throw:
    // guardAssociation() in envoyerEmail rejects cross-tenant instances
    expect(fn () => app(DevisService::class)->envoyerEmail($devisA, 'Sujet', 'Corps'))
        ->toThrow(RuntimeException::class, 'Accès interdit');
});

// ── DevisService::dupliquer: scope prevents cross-tenant duplication ──────────

it('DevisService::dupliquer cannot be called on asso A devis from asso B context', function () {
    // Pre-fetch devisA in asso A's context (scoped)
    TenantContext::boot($this->assoA);
    $devisA = Devis::find($this->devisA->id);
    expect($devisA)->not->toBeNull();

    // Switch to asso B context
    TenantContext::clear();
    TenantContext::boot($this->assoB);
    session(['current_association_id' => $this->assoB->id]);
    $this->actingAs($this->userB);

    // Devis from A is invisible under B's scope via normal queries
    $devisUnderB = Devis::find($this->devisA->id);
    expect($devisUnderB)->toBeNull();

    // Direct service call with asso A's devis from asso B context must throw:
    // guardAssociation() in dupliquer rejects cross-tenant instances
    expect(fn () => app(DevisService::class)->dupliquer($devisA))
        ->toThrow(RuntimeException::class, 'Accès interdit');
});

// ── Vue 360° (TiersQuickViewService via DevisList filter) ─────────────────────

it('DevisList filter by tiers_id from asso A does not expose asso A devis to asso B user', function () {
    TenantContext::boot($this->assoB);
    session(['current_association_id' => $this->assoB->id]);
    $this->actingAs($this->userB);

    // Even if user B somehow passes tiers A's id as the filter,
    // the tenant scope ensures zero results are returned.
    Livewire::test(DevisList::class)
        ->set('filtreTiersId', (int) $this->tiersA->id)
        ->assertDontSee('Devis secret asso A')
        ->assertDontSee('D-2026-014');

    $component = Livewire::test(DevisList::class)
        ->set('filtreTiersId', (int) $this->tiersA->id);
    $devis = $component->viewData('devis');
    expect($devis->total())->toBe(0);
});

// ── Fail-closed: no tenant context returns zero results ───────────────────────

it('Devis query returns zero results when TenantContext is not booted (fail-closed)', function () {
    // Explicitly clear — simulate a context not booted scenario.
    TenantContext::clear();

    // With fail-closed scope (WHERE 1 = 0 when no tenant), no devis should be visible.
    expect(Devis::count())->toBe(0);
});
