<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DevisService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create([
        'devis_validite_jours' => 30,
        'nom' => 'Association Test',
        'facture_mentions_legales' => 'TVA non applicable, art. 261-7-1° du CGI',
    ]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create([
        'nom' => 'ACME',
        'prenom' => null,
    ]);
    $this->service = app(DevisService::class);
});

afterEach(function () {
    TenantContext::clear();
    Carbon::setTestNow();
});

// ─── Test 1 : brouillon avec filigrane ──────────────────────────────────────

it('genererPdf sur un brouillon retourne un path et le HTML contient BROUILLON sans numéro', function () {
    $devis = Devis::factory()->brouillon()->create([
        'tiers_id' => $this->tiers->id,
        'numero' => null,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Mission conseil',
        'prix_unitaire' => 500.00,
        'quantite' => 1.0,
        'montant' => 500.00,
        'ordre' => 1,
    ]);

    $path = $this->service->genererPdf($devis);

    // Path retourné non vide
    expect($path)->toBeString()->not->toBeEmpty();

    // Fichier écrit sur le fake disk
    Storage::disk('local')->assertExists($path);

    // Path suit la convention associations/{id}/devis-libres/{devis_id}/devis-*.pdf
    expect($path)->toContain('associations/'.$this->association->id.'/devis-libres/'.$devis->id.'/devis-');
    expect($path)->toEndWith('.pdf');

    // HTML rendu contient "BROUILLON" (filigrane textuel)
    $devis->load(['lignes', 'tiers']);
    $html = view('pdf.devis-libre', [
        'devis' => $devis,
        'lignes' => $devis->lignes,
        'association' => $this->association,
        'brouillonWatermark' => true,
    ])->render();

    expect($html)->toContain('BROUILLON');

    // Pas de numéro de référence dans le HTML brouillon
    expect($html)->not->toContain('D-');
});

// ─── Test 2 : envoyé — numéro, tiers, lignes, total, mentions ───────────────

it('genererPdf sur un devis envoyé produit un HTML avec numéro, tiers, lignes, total et mentions', function () {
    Carbon::setTestNow('2026-05-01');

    $devis = Devis::factory()->envoye()->create([
        'tiers_id' => $this->tiers->id,
        'numero' => 'D-2026-001',
        'date_emission' => '2026-05-01',
        'date_validite' => '2026-05-31',
        'montant_total' => 2400.00,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Mission audit',
        'prix_unitaire' => 800.00,
        'quantite' => 3.0,
        'montant' => 2400.00,
        'ordre' => 1,
    ]);

    $path = $this->service->genererPdf($devis);

    Storage::disk('local')->assertExists($path);

    $devis->load(['lignes', 'tiers']);
    $html = view('pdf.devis-libre', [
        'devis' => $devis,
        'lignes' => $devis->lignes,
        'association' => $this->association,
        'brouillonWatermark' => false,
    ])->render();

    // Numéro de référence présent
    expect($html)->toContain('D-2026-001');

    // Date d'émission
    expect($html)->toContain('01/05/2026');

    // Date de validité
    expect($html)->toContain('31/05/2026');

    // Nom du tiers
    expect($html)->toContain('ACME');

    // Libellé de ligne
    expect($html)->toContain('Mission audit');

    // Montant total formaté
    expect($html)->toContain('2');

    // Mentions légales de l'association
    expect($html)->toContain('TVA non applicable');

    // Pas de filigrane BROUILLON pour un devis envoyé
    expect($html)->not->toContain('BROUILLON');
});

// ─── Test 3 : refus si aucune ligne ──────────────────────────────────────────

it('genererPdf refuse si le devis n\'a aucune ligne', function () {
    $devis = Devis::factory()->brouillon()->create([
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0,
    ]);

    expect(fn () => $this->service->genererPdf($devis))
        ->toThrow(RuntimeException::class, 'Le devis doit avoir au moins une ligne avec un montant pour générer un PDF.');
});

// ─── Test 4 : refus si toutes les lignes ont montant = 0 ─────────────────────

it('genererPdf refuse si toutes les lignes ont montant = 0', function () {
    $devis = Devis::factory()->brouillon()->create([
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Ligne gratuite',
        'prix_unitaire' => 0.00,
        'quantite' => 1.0,
        'montant' => 0.00,
        'ordre' => 1,
    ]);

    expect(fn () => $this->service->genererPdf($devis))
        ->toThrow(RuntimeException::class, 'Le devis doit avoir au moins une ligne avec un montant pour générer un PDF.');
});

// ─── Test 5 : brouillonWatermark=true sur Envoyé ajoute le filigrane ─────────

it('genererPdf avec brouillonWatermark=true sur un devis envoyé produit un HTML avec BROUILLON', function () {
    $devis = Devis::factory()->envoye()->create([
        'tiers_id' => $this->tiers->id,
        'numero' => 'D-2026-002',
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Ligne test',
        'prix_unitaire' => 100.00,
        'quantite' => 1.0,
        'montant' => 100.00,
        'ordre' => 1,
    ]);

    $this->service->genererPdf($devis, brouillonWatermark: true);

    $devis->load(['lignes', 'tiers']);
    $html = view('pdf.devis-libre', [
        'devis' => $devis,
        'lignes' => $devis->lignes,
        'association' => $this->association,
        'brouillonWatermark' => true,
    ])->render();

    expect($html)->toContain('BROUILLON');
});

// ─── Test 6 : brouillonWatermark=false sur Brouillon supprime le filigrane ────

it('genererPdf avec brouillonWatermark=false sur un brouillon n\'affiche pas BROUILLON dans le HTML', function () {
    $devis = Devis::factory()->brouillon()->create([
        'tiers_id' => $this->tiers->id,
        'numero' => null,
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Service conseil',
        'prix_unitaire' => 200.00,
        'quantite' => 1.0,
        'montant' => 200.00,
        'ordre' => 1,
    ]);

    $this->service->genererPdf($devis, brouillonWatermark: false);

    $devis->load(['lignes', 'tiers']);
    $html = view('pdf.devis-libre', [
        'devis' => $devis,
        'lignes' => $devis->lignes,
        'association' => $this->association,
        'brouillonWatermark' => false,
    ])->render();

    expect($html)->not->toContain('BROUILLON');
});

// ─── Test 7 : convention du path de stockage ──────────────────────────────────

it('genererPdf suit la convention de path associations/{id}/devis-libres/{devis_id}/devis-*.pdf', function () {
    $devis = Devis::factory()->envoye()->create([
        'tiers_id' => $this->tiers->id,
        'numero' => 'D-2026-003',
    ]);

    DevisLigne::factory()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Prestation',
        'prix_unitaire' => 300.00,
        'quantite' => 1.0,
        'montant' => 300.00,
        'ordre' => 1,
    ]);

    $path = $this->service->genererPdf($devis);

    $expectedPrefix = 'associations/'.$this->association->id.'/devis-libres/'.$devis->id.'/devis-';

    expect($path)->toStartWith($expectedPrefix);
    expect($path)->toEndWith('.pdf');

    Storage::disk('local')->assertExists($path);
});
