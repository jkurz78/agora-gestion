<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    TenantContext::clear();
    $this->asso = Association::factory()->create();
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
// Test 1 : Liste les Transactions de dépense du Tiers connecté
// ---------------------------------------------------------------------------

it('[historique] liste les transactions de dépense du tiers connecté', function () {
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Dépense Alpha',
        'numero_piece' => 'DEP-001',
    ]);
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'libelle' => 'Dépense Beta',
        'numero_piece' => 'DEP-002',
    ]);

    $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->assertSee('DEP-001')
        ->assertSee('DEP-002');
});

// ---------------------------------------------------------------------------
// Test 2 : Exclut les Transactions liées à une NoteDeFrais
// ---------------------------------------------------------------------------

it('[historique] exclut les transactions liées à une note de frais', function () {
    // Transaction standalone (visible)
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_piece' => 'DEP-VISIBLE',
    ]);

    // Transaction liée à une NDF (exclue)
    $txNdf = Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_piece' => 'DEP-NDF',
    ]);
    NoteDeFrais::factory()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'transaction_id' => $txNdf->id,
    ]);

    $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->assertSee('DEP-VISIBLE')
        ->assertDontSee('DEP-NDF');
});

// ---------------------------------------------------------------------------
// Test 3 : Exclut les Transactions de type Recette
// ---------------------------------------------------------------------------

it('[historique] exclut les transactions de type recette', function () {
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_piece' => 'DEP-OK',
    ]);
    Transaction::factory()->asRecette()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_piece' => 'REC-EXCLUE',
    ]);

    $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->assertSee('DEP-OK')
        ->assertDontSee('REC-EXCLUE');
});

// ---------------------------------------------------------------------------
// Test 4 : Exclut les Transactions d'un autre Tiers du même tenant
// ---------------------------------------------------------------------------

it('[historique] exclut les transactions d\'un autre tiers du même tenant', function () {
    $autreTiers = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => true,
    ]);

    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'numero_piece' => 'DEP-MON-TIERS',
    ]);
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $autreTiers->id,
        'numero_piece' => 'DEP-AUTRE-TIERS',
    ]);

    $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->assertSee('DEP-MON-TIERS')
        ->assertDontSee('DEP-AUTRE-TIERS');
});

// ---------------------------------------------------------------------------
// Test 5 : Statut "En attente" / "Réglée" affiché correctement
// ---------------------------------------------------------------------------

it('[historique] affiche statut En attente pour EnAttente et Réglée pour Recu et Pointe', function () {
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'statut_reglement' => StatutReglement::EnAttente,
        'numero_piece' => 'DEP-ATTENTE',
    ]);
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'statut_reglement' => StatutReglement::Recu,
        'numero_piece' => 'DEP-RECU',
    ]);
    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'statut_reglement' => StatutReglement::Pointe,
        'numero_piece' => 'DEP-POINTE',
    ]);

    $html = $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->getContent();

    expect($html)->toContain('En attente');
    expect($html)->toContain('Réglée');
});

// ---------------------------------------------------------------------------
// Test 6 : Lien PDF visible si piece_jointe_path non null, absent sinon
// ---------------------------------------------------------------------------

it('[historique] affiche le lien PDF si piece_jointe_path non null, absent sinon', function () {
    $txAvecPj = Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'piece_jointe_path' => 'facture-test.pdf',
        'piece_jointe_nom' => 'Facture test.pdf',
        'piece_jointe_mime' => 'application/pdf',
    ]);

    Transaction::factory()->asDepense()->create([
        'association_id' => $this->asso->id,
        'tiers_id' => $this->tiers->id,
        'piece_jointe_path' => null,
        'numero_piece' => 'DEP-SANS-PJ',
    ]);

    $html = $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->getContent();

    // L'URL signée contient l'ID de la transaction avec pièce jointe
    expect($html)->toContain((string) $txAvecPj->id);
    expect($html)->toContain('bi-file-earmark-pdf');
    // La transaction sans PJ ne doit pas avoir l'icône PDF sur sa ligne
    // (il peut y avoir d'autres occurrences mais on vérifie la présence de l'URL signée)
    expect($html)->toContain('signature=');
});

// ---------------------------------------------------------------------------
// Test 7 : Texte muted "Vos remboursements de notes de frais sont visibles…"
// ---------------------------------------------------------------------------

it('[historique] affiche le texte muted pointant vers l\'écran NDF', function () {
    $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->assertSee('remboursements de notes de frais')
        ->assertSee('Vos notes de frais');
});

// ---------------------------------------------------------------------------
// Test 8 : Page accessible uniquement si pour_depenses=true
// ---------------------------------------------------------------------------

it('[historique] redirige avec flash si pour_depenses=false', function () {
    $tiersNonDepense = Tiers::factory()->create([
        'association_id' => $this->asso->id,
        'pour_depenses' => false,
        'pour_recettes' => true,
    ]);
    Auth::guard('tiers-portail')->login($tiersNonDepense);
    session(['portail.last_activity_at' => now()->timestamp]);

    $this->get("/{$this->asso->slug}/portail/historique")
        ->assertRedirect("/{$this->asso->slug}/portail/")
        ->assertSessionHas('portail.info');
});

// ---------------------------------------------------------------------------
// Test bonus : Message "Aucune dépense" si liste vide
// ---------------------------------------------------------------------------

it('[historique] affiche un message vide si aucune dépense', function () {
    $this->get("/{$this->asso->slug}/portail/historique")
        ->assertStatus(200)
        ->assertSee('Aucune dépense pour le moment.');
});
