<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Devis;
use App\Models\DevisLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create([
        'nom' => 'Association Test PDF',
        'facture_mentions_legales' => null,
    ]);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    TenantContext::boot($this->association);
    $this->actingAs($this->user);

    $this->tiers = Tiers::factory()->create([
        'nom' => 'CLIENT TEST',
        'prenom' => null,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Test : ligne Texte — cellules PU/Qté/Montant vides ──────────────────────

it('une ligne Texte affiche son libellé mais pas de valeur monétaire dans PU/Qté/Montant', function (): void {
    $devis = Devis::factory()->valide()->create([
        'tiers_id' => $this->tiers->id,
        'numero' => 'D-2026-099',
        'date_emission' => '2026-05-01',
        'date_validite' => '2026-05-31',
        'montant_total' => 200.00,
    ]);

    // Ligne Montant : PU=100, qty=2, montant=200
    $ligneMontant = DevisLigne::factory()->montant()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Prestation conseil',
        'prix_unitaire' => 100.00,
        'quantite' => 2.0,
        'montant' => 200.00,
        'ordre' => 1,
    ]);

    // Ligne Texte : libellé seulement, sans valeurs numériques
    $ligneTexte = DevisLigne::factory()->texte()->create([
        'devis_id' => $devis->id,
        'libelle' => 'Détail prestation',
        'ordre' => 2,
    ]);

    $devis->load(['lignes', 'tiers']);

    $html = view('pdf.devis-libre', [
        'devis' => $devis,
        'lignes' => $devis->lignes,
        'association' => $this->association,
        'brouillonWatermark' => false,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
    ])->render();

    // Le libellé de la ligne Texte est bien présent
    expect($html)->toContain('Détail prestation');

    // La ligne Montant affiche ses valeurs correctement
    expect($html)->toContain('100,00');
    expect($html)->toContain('200,00');

    // La ligne Texte ne doit PAS afficher 0,00 dans une cellule <td>
    // (les colonnes PU / Qté / Montant doivent être vides)
    // On cherche la séquence "<td" … "0,00" qui trahit un affichage erroné
    // dans une cellule numérique de ligne Texte.
    // Stratégie : le HTML ne doit pas contenir "<td>0,00" ni "<td class="text-end">0,00"
    expect($html)->not->toContain('<td>0,00');
    expect($html)->not->toContain('>0,00<');
    expect($html)->not->toContain('>0<');
});
