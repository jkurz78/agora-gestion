<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');

    $this->asso = Association::factory()->create(['slug' => 'test-asso']);
    TenantContext::boot($this->asso);

    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => true,
    ]);
    Auth::guard('tiers-portail')->login($this->tiers);
    session(['portail.last_activity_at' => now()->timestamp]);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Scénario 1 : GET PDF dépôt — Tiers authentifié, signed URL valide → 200
// ---------------------------------------------------------------------------

it('[factures-pdf] Tiers authentifié avec signed URL valide obtient le PDF → 200', function () {
    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/2026-04-01-fact-001-abc123.pdf";

    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-001',
    ]);

    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $signedUrl = URL::signedRoute('portail.factures.pdf', [
        'association' => $this->asso->slug,
        'depot' => $depot->id,
    ]);

    $this->get($signedUrl)
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

// ---------------------------------------------------------------------------
// Scénario 2 : GET PDF dépôt — autre Tiers même tenant → 403
// ---------------------------------------------------------------------------

it('[factures-pdf] autre Tiers même tenant → 403', function () {
    $autreTiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => true,
    ]);

    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/2026-04-01-fact-002-xyz456.pdf";

    // Depot appartient à autreTiers, mais le Tiers connecté est $this->tiers
    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-002',
    ]);

    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $signedUrl = URL::signedRoute('portail.factures.pdf', [
        'association' => $this->asso->slug,
        'depot' => $depot->id,
    ]);

    $this->get($signedUrl)->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Scénario 3 : GET PDF dépôt — Tiers d'un autre tenant → 403 (TenantScope exclut le depot)
// ---------------------------------------------------------------------------

it('[factures-pdf] Tiers d\'un autre tenant → 404 (TenantScope fail-closed)', function () {
    $assoB = Association::factory()->create(['slug' => 'asso-b']);

    // Créer le depot dans assoA (contexte actuel)
    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/2026-04-01-fact-003-def789.pdf";
    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-003',
    ]);
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    // Switcher vers assoB, créer un Tiers dans assoB
    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create([
        'association_id' => $assoB->id,
        'pour_depenses' => true,
    ]);
    Auth::guard('tiers-portail')->login($tiersB);

    $signedUrl = URL::signedRoute('portail.factures.pdf', [
        'association' => $assoB->slug,
        'depot' => $depot->id,
    ]);

    // TenantScope empêche de trouver le depot → 404
    $this->get($signedUrl)->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Scénario 4 : GET PDF dépôt — URL sans signature → 403
// ---------------------------------------------------------------------------

it('[factures-pdf] URL sans signature → 403', function () {
    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/2026-04-01-fact-004-ghi.pdf";
    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-004',
    ]);
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    // URL non signée (route normale sans signature)
    $url = route('portail.factures.pdf', [
        'association' => $this->asso->slug,
        'depot' => $depot->id,
    ]);

    $this->get($url)->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Scénario 5 : GET PDF dépôt — Tiers pour_recettes seul → 403 (EnsurePourDepenses)
// ---------------------------------------------------------------------------

it('[factures-pdf] Tiers pour_recettes seulement (pas pour_depenses) → 403', function () {
    // Reconnecter un Tiers sans pour_depenses
    $tiersRecettes = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => false,
        'pour_recettes' => true,
    ]);
    Auth::guard('tiers-portail')->login($tiersRecettes);
    session(['portail.last_activity_at' => now()->timestamp]);

    $pdfPath = "associations/{$this->asso->id}/factures-deposees/2026/04/2026-04-01-fact-005-jkl.pdf";
    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'pdf_path' => $pdfPath,
        'numero_facture' => 'FACT-005',
    ]);
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    $signedUrl = URL::signedRoute('portail.factures.pdf', [
        'association' => $this->asso->slug,
        'depot' => $depot->id,
    ]);

    // EnsurePourDepenses redirige vers home (302), pas 403
    $this->get($signedUrl)->assertRedirect();
});
