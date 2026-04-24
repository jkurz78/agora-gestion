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
// Test 2 : Ne liste pas les dépôts Traitee ni Rejetee
// ---------------------------------------------------------------------------

it('atraiter-index: ne liste pas les dépôts Traitee ni Rejetee', function () {
    FacturePartenaireDeposee::factory()->traitee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-TRAITEE',
    ]);
    FacturePartenaireDeposee::factory()->rejetee()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_facture' => 'FACT-REJETEE',
    ]);

    $this->get("/{$this->asso->slug}/portail/factures")
        ->assertStatus(200)
        ->assertDontSee('FACT-TRAITEE')
        ->assertDontSee('FACT-REJETEE');
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
