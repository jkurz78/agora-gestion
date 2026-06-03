<?php

declare(strict_types=1);

/**
 * [QF-B] TransactionForm::save() — statut_reglement pour les recettes.
 *
 * Audit Thème B : avant le fix, une recette comptant naissait toujours
 * avec statut_reglement = en_attente (valeur de la colonne par défaut).
 * Ce fichier couvre :
 *
 *  1. Recette comptant → statut_reglement = Recu     (QF-B principal)
 *  2. Recette créance  → statut_reglement = EnAttente (comportement inchangé)
 *  3. Édition d'une transaction déjà Pointe → ne rétrograde pas vers Recu/EnAttente
 *  4. Recette comptant créée → statut_reglement->isEncaisse() est true
 */

use App\Enums\StatutReglement;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->association = Association::factory()->create(['exercice_mois_debut' => 9]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Compte bancaire utilisé dans les formulaires
    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);

    // Sous-catégorie recette (sans usage spécial pour rester hors inscription-guard)
    $categorieRecette = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
    ]);
    $this->scRecette = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorieRecette->id,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/** Renvoie la date d'aujourd'hui (dans l'exercice courant). */
function dateExercice(): string
{
    return now()->format('Y-m-d');
}

// ---------------------------------------------------------------------------
// [QF-B] 1 — Recette comptant → statut_reglement = Recu
// ---------------------------------------------------------------------------

it('[QF-B] une recette comptant (paiementRecu=true) est créée avec statut_reglement = Recu', function () {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', dateExercice())
        ->set('libelle', 'Cotisation annuelle')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'cheque')
        ->set('compte_id', $this->compte->id)
        ->set('lignes', [[
            'sous_categorie_id' => (string) $this->scRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '50.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save');

    // Récupérer la transaction créée dans ce tenant
    $tx = Transaction::where('association_id', $this->association->id)
        ->where('type', 'recette')
        ->latest('id')
        ->firstOrFail();

    expect($tx->statut_reglement)->toBe(StatutReglement::Recu);
});

// ---------------------------------------------------------------------------
// 2 — Recette créance (paiementRecu=false) → statut_reglement = EnAttente
// ---------------------------------------------------------------------------

it('une recette créance (paiementRecu=false) est créée avec statut_reglement = EnAttente', function () {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', dateExercice())
        ->set('libelle', 'Créance à encaisser')
        ->set('paiementRecu', false)
        ->set('mode_paiement', '') // pas de mode pour une créance
        ->set('compte_id', $this->compte->id)
        ->set('lignes', [[
            'sous_categorie_id' => (string) $this->scRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '75.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save');

    $tx = Transaction::where('association_id', $this->association->id)
        ->where('type', 'recette')
        ->latest('id')
        ->firstOrFail();

    expect($tx->statut_reglement)->toBe(StatutReglement::EnAttente);
});

// ---------------------------------------------------------------------------
// 3 — Édition d'une transaction Pointe → ne rétrograde pas
// ---------------------------------------------------------------------------

it('éditer une transaction déjà Pointe ne rétrograde pas le statut_reglement', function () {
    // Créer une transaction déjà pointée (statut Pointe)
    $txPointe = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => dateExercice(),
        'statut_reglement' => StatutReglement::Pointe,
        'mode_paiement' => 'cheque',
        'compte_id' => $this->compte->id,
    ]);

    // Lui associer une ligne de ventilation (la factory en crée mais on en crée une propre)
    // On supprime les lignes factory et on crée une ligne conforme (ventilation)
    $txPointe->lignes()->forceDelete();
    $txPointe->lignes()->create([
        'sous_categorie_id' => $this->scRecette->id,
        'montant' => '100.00',
    ]);

    // Éditer via TransactionForm
    Livewire::test(TransactionForm::class)
        ->call('edit', $txPointe->id)
        ->set('libelle', 'Libellé mis à jour')
        ->set('paiementRecu', true) // l'utilisateur coche "reçu" — ne doit PAS écraser Pointe
        ->set('mode_paiement', 'cheque')
        ->call('save');

    $txPointe->refresh();

    // Le statut doit rester Pointe
    expect($txPointe->statut_reglement)->toBe(StatutReglement::Pointe);
});

// ---------------------------------------------------------------------------
// 4 — isEncaisse() est true après création d'une recette comptant
// ---------------------------------------------------------------------------

it('une recette comptant créée via TransactionForm a statut_reglement->isEncaisse() = true', function () {
    Livewire::test(TransactionForm::class)
        ->set('type', 'recette')
        ->set('date', dateExercice())
        ->set('libelle', 'Recette encaissée')
        ->set('paiementRecu', true)
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compte->id)
        ->set('lignes', [[
            'sous_categorie_id' => (string) $this->scRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '120.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save');

    $tx = Transaction::where('association_id', $this->association->id)
        ->where('type', 'recette')
        ->latest('id')
        ->firstOrFail();

    expect($tx->statut_reglement->isEncaisse())->toBeTrue();
});
