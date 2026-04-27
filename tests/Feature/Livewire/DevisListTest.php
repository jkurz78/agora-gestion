<?php

declare(strict_types=1);

use App\Enums\StatutDevis;
use App\Livewire\DevisLibre\DevisList;
use App\Models\Association;
use App\Models\Devis;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    $this->exercice = app(ExerciceService::class)->current();

    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'ACME',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

// ── Isolation multi-tenant ──────────────────────────────────────────────────

it('shows only devis from the current tenant', function () {
    // Devis in the current tenant
    $devisA = Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Devis asso A',
        'exercice' => $this->exercice,
    ]);

    // Devis in a different tenant
    $autreAsso = Association::factory()->create();
    $autreTiers = Tiers::factory()->create(['association_id' => $autreAsso->id]);
    Devis::factory()->create([
        'association_id' => $autreAsso->id,
        'tiers_id' => $autreTiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Devis asso B',
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->assertSee('Devis asso A')
        ->assertDontSee('Devis asso B');
});

// ── Filtre par défaut : exclure les Annulés ─────────────────────────────────

it('excludes annule devis by default', function () {
    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Devis brouillon',
        'exercice' => $this->exercice,
    ]);

    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Annule,
        'libelle' => 'Devis annulé',
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->assertSee('Devis brouillon')
        ->assertDontSee('Devis annulé');
});

// ── Filtre statut = 'annule' : seulement les annulés ────────────────────────

it('shows only annule devis when filtreStatut is annule', function () {
    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Devis brouillon visible',
        'exercice' => $this->exercice,
    ]);

    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Annule,
        'libelle' => 'Devis annulé visible',
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->set('filtreStatut', 'annule')
        ->assertSee('Devis annulé visible')
        ->assertDontSee('Devis brouillon visible');
});

// ── Filtre statut spécifique ─────────────────────────────────────────────────

it('shows only envoye devis when filtreStatut is envoye', function () {
    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Un brouillon',
        'exercice' => $this->exercice,
    ]);

    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Envoye,
        'numero' => 'D-2026-001',
        'libelle' => 'Devis envoyé',
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->set('filtreStatut', 'envoye')
        ->assertSee('Devis envoyé')
        ->assertDontSee('Un brouillon');
});

// ── Filtre par tiers ──────────────────────────────────────────────────────────

it('filters by tiers_id', function () {
    $autresTiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Autre Tiers',
    ]);

    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Devis ACME',
        'exercice' => $this->exercice,
    ]);

    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $autresTiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Devis Autre',
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->set('filtreTiersId', (int) $this->tiers->id)
        ->assertSee('Devis ACME')
        ->assertDontSee('Devis Autre');
});

// ── Filtre exercice ─────────────────────────────────────────────────────────

it('filters by exercice', function () {
    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Devis exercice courant',
        'exercice' => $this->exercice,
    ]);

    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Devis vieux exercice',
        'exercice' => $this->exercice - 1,
    ]);

    Livewire::test(DevisList::class, ['filtreExercice' => $this->exercice])
        ->assertSee('Devis exercice courant')
        ->assertDontSee('Devis vieux exercice');
});

// ── Filtre recherche ────────────────────────────────────────────────────────

it('filters by search on libelle', function () {
    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Mission audit complet',
        'exercice' => $this->exercice,
    ]);

    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'libelle' => 'Prestation formation',
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->set('search', 'audit')
        ->assertSee('Mission audit complet')
        ->assertDontSee('Prestation formation');
});

it('filters by search on numero', function () {
    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Envoye,
        'numero' => 'D-2026-042',
        'libelle' => 'Devis quarante-deux',
        'exercice' => $this->exercice,
    ]);

    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Envoye,
        'numero' => 'D-2026-007',
        'libelle' => 'Devis sept',
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->set('search', '042')
        ->assertSee('D-2026-042')
        ->assertDontSee('D-2026-007');
});

// ── Badge Expiré ────────────────────────────────────────────────────────────

it('shows badge expire for envoye devis with past date_validite', function () {
    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Envoye,
        'numero' => 'D-2026-010',
        'libelle' => 'Devis expiré',
        'date_validite' => Carbon::today()->subDays(1),
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->set('filtreStatut', 'envoye')
        ->assertSee('Expiré');
});

it('does not show badge expire for envoye devis with future date_validite', function () {
    Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Envoye,
        'numero' => 'D-2026-011',
        'libelle' => 'Devis pas expiré',
        'date_validite' => Carbon::today()->addDays(10),
        'exercice' => $this->exercice,
    ]);

    Livewire::test(DevisList::class)
        ->set('filtreStatut', 'envoye')
        ->assertDontSee('Expiré');
});

// ── Pagination ──────────────────────────────────────────────────────────────

it('paginates at 50 per page', function () {
    // Create 55 brouillon devis
    Devis::factory()->count(55)->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Brouillon,
        'exercice' => $this->exercice,
    ]);

    $component = Livewire::test(DevisList::class);

    // The paginated collection should show 50 items on the first page
    $devis = $component->viewData('devis');
    expect($devis)->toHaveCount(50);
});

// ── Action creerDevis ────────────────────────────────────────────────────────

it('dispatches creer-devis event when creerDevis is called', function () {
    Livewire::test(DevisList::class)
        ->call('creerDevis')
        ->assertDispatched('creer-devis');
});
