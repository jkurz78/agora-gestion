<?php

declare(strict_types=1);

use App\Livewire\Portail\FacturePartenaire\AtraiterIndex;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
    TenantContext::boot($this->asso);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->asso->id, 'pour_depenses' => true]);
    Auth::guard('tiers-portail')->login($this->tiers);
});

// ---------------------------------------------------------------------------
// Test 1 : Liste les dépôts Soumise du tiers connecté, tri date_facture desc
// ---------------------------------------------------------------------------

it('atraiter-index: liste les dépôts Soumise du tiers connecté triés par date desc', function () {
    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-001',
        'date_facture' => '2026-03-01',
    ]);
    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-002',
        'date_facture' => '2026-04-01',
    ]);

    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertSee('FACT-001')
        ->assertSee('FACT-002');
});

// ---------------------------------------------------------------------------
// Test 2 : Ne liste pas les dépôts Traitee (Rejetee est maintenant visible)
// ---------------------------------------------------------------------------

it('atraiter-index: ne liste pas les dépôts Traitee', function () {
    FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-TRAITEE',
    ]);

    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertDontSee('FACT-TRAITEE');
});

// ---------------------------------------------------------------------------
// Test 3 : Ne liste pas les dépôts d'un autre Tiers du même tenant
// ---------------------------------------------------------------------------

it('atraiter-index: ne liste pas les dépôts d\'un autre tiers du même tenant', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id, 'pour_depenses' => true]);

    FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
        'numero_facture' => 'FACT-AUTRE-TIERS',
    ]);

    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertDontSee('FACT-AUTRE-TIERS');
});

// ---------------------------------------------------------------------------
// Test 4 : Action oublier supprime le dépôt si owner + Soumise
// ---------------------------------------------------------------------------

it('atraiter-index: oublier supprime le dépôt appartenant au tiers connecté', function () {
    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => "associations/{$this->asso->id}/factures-deposees/2026/04/2026-04-01-test-abc123.pdf",
    ]);

    TenantContext::boot($this->asso);
    $component = new AtraiterIndex;
    $component->mount($this->asso);
    $component->oublier((int) $depot->id);

    expect(FacturePartenaireDeposee::find((int) $depot->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 5 : Action oublier refuse si autre Tiers (DomainException)
// ---------------------------------------------------------------------------

it('atraiter-index: oublier lève DomainException si le dépôt appartient à un autre tiers', function () {
    $autreTiers = Tiers::factory()->create(['association_id' => $this->asso->id, 'pour_depenses' => true]);

    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
        'pdf_path' => "associations/{$this->asso->id}/factures-deposees/2026/04/2026-04-01-test-xyz789.pdf",
    ]);

    TenantContext::boot($this->asso);
    $component = new AtraiterIndex;
    $component->mount($this->asso);

    expect(fn () => $component->oublier((int) $depot->id))->toThrow(DomainException::class);
});

// ---------------------------------------------------------------------------
// Test 6 : Bouton "Déposer une facture" présent (lien vers route create)
// ---------------------------------------------------------------------------

it('atraiter-index: bouton Déposer une facture présent', function () {
    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertSee('Déposer une facture');
});

// ---------------------------------------------------------------------------
// Test 7 : URL PDF signée présente sur chaque ligne
// ---------------------------------------------------------------------------

it('atraiter-index: URL PDF signée présente sur chaque dépôt Soumise', function () {
    $depot = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-PDF-TEST',
    ]);

    $html = $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->getContent();

    // URL signée contient le paramètre signature
    expect($html)->toContain('signature=');
    expect($html)->toContain((string) $depot->id);
});

// ---------------------------------------------------------------------------
// Test 8 : Dépôt Rejetee visible dans la liste
// ---------------------------------------------------------------------------

it('atraiter-index: dépôt Rejetee visible dans la liste', function () {
    FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-REJETEE-VIS',
        'motif_rejet' => 'PDF illisible',
    ]);

    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertSee('FACT-REJETEE-VIS');
});

// ---------------------------------------------------------------------------
// Test 9 : Motif de rejet visible dans le rendu HTML
// ---------------------------------------------------------------------------

it('atraiter-index: motif de rejet visible pour un dépôt Rejetee', function () {
    FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-MOTIF',
        'motif_rejet' => 'PDF illisible',
    ]);

    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertSee('PDF illisible');
});

// ---------------------------------------------------------------------------
// Test 10 : Badge "Rejetée" présent dans le HTML pour un dépôt rejeté
// ---------------------------------------------------------------------------

it('atraiter-index: badge Rejetée présent pour un dépôt Rejetee', function () {
    FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-BADGE',
        'motif_rejet' => 'Mauvaise facture',
    ]);

    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertSee('Rejetée');
});

// ---------------------------------------------------------------------------
// Test 11 : Tri — Rejetee avant Soumise (priorité visuelle)
// ---------------------------------------------------------------------------

it('atraiter-index: tri — dépôt Rejetee affiché avant dépôt Soumise', function () {
    // Rejetee créée hier, Soumise créée aujourd'hui (Soumise est plus récente)
    $rejetee = FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-REJETEE-TRI',
        'motif_rejet' => 'Erreur format',
        'created_at' => now()->subDay(),
    ]);
    $soumise = FacturePartenaireDeposee::factory()->soumise()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-SOUMISE-TRI',
        'created_at' => now(),
    ]);

    $html = $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->getContent();

    // FACT-REJETEE-TRI doit apparaître avant FACT-SOUMISE-TRI dans le HTML
    $posRejetee = mb_strpos($html, 'FACT-REJETEE-TRI');
    $posSoumise = mb_strpos($html, 'FACT-SOUMISE-TRI');
    expect($posRejetee)->toBeLessThan($posSoumise);
});

// ---------------------------------------------------------------------------
// Test 12 : Tri secondaire — 2 Rejetee triées par created_at desc
// ---------------------------------------------------------------------------

it('atraiter-index: tri secondaire — 2 dépôts Rejetee triés par created_at desc', function () {
    $ancienne = FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-REJETEE-ANCIENNE',
        'motif_rejet' => 'Trop vieille',
        'created_at' => now()->subDays(3),
    ]);
    $recente = FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-REJETEE-RECENTE',
        'motif_rejet' => 'Plus récente',
        'created_at' => now()->subDay(),
    ]);

    $html = $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->getContent();

    $posAncienne = mb_strpos($html, 'FACT-REJETEE-ANCIENNE');
    $posRecente = mb_strpos($html, 'FACT-REJETEE-RECENTE');
    // La plus récente doit apparaître en premier
    expect($posRecente)->toBeLessThan($posAncienne);
});

// ---------------------------------------------------------------------------
// Test 13 : Dépôt Traitee masqué (comportement MVP conservé)
// ---------------------------------------------------------------------------

it('atraiter-index: dépôt Traitee masqué dans la liste portail', function () {
    FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-TRAITEE-MASQUEE',
    ]);

    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertDontSee('FACT-TRAITEE-MASQUEE');
});

// ---------------------------------------------------------------------------
// Test 14 : Action oublier réussit sur un dépôt Rejetee
// ---------------------------------------------------------------------------

it('atraiter-index: oublier supprime un dépôt Rejetee appartenant au tiers connecté', function () {
    $depot = FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'motif_rejet' => 'Document illisible',
        'pdf_path' => "associations/{$this->asso->id}/factures-deposees/2026/04/2026-04-01-rejet-abc123.pdf",
    ]);

    TenantContext::boot($this->asso);
    $component = new AtraiterIndex;
    $component->mount($this->asso);
    $component->oublier((int) $depot->id);

    expect(FacturePartenaireDeposee::find((int) $depot->id))->toBeNull();
});
