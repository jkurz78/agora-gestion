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

use App\Enums\JournalComptable;
use App\Enums\StatutReglement;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\TransactionService;
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

    // Comptes système (411, 401, 512X…) : nécessaires à la génération PD dès
    // qu'un tiers est présent (le tiers est désormais obligatoire).
    SystemeSeeder::seed();

    // Compte bancaire utilisé dans les formulaires
    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);
    Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '512_'.$this->compte->id,
        'classe' => 5,
        'compte_bancaire_id' => $this->compte->id,
    ]);

    $this->compteRecette = Compte::factory()->numero('706')->create();

    // Tiers obligatoire pour toute recette/dépense (porte la contrepartie 411/401).
    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
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
        // virement → 512X → statut dérivé Recu (un chèque irait en 5112 → EnMain).
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $this->compte->id)
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '50.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save');

    // Récupérer la T1 opérationnelle (le libellé distingue de la T2 d'encaissement).
    $tx = Transaction::where('association_id', $this->association->id)
        ->where('libelle', 'Cotisation annuelle')
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
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '75.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save');

    $tx = Transaction::where('association_id', $this->association->id)
        ->where('libelle', 'Créance à encaisser')
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
        'tiers_id' => $this->tiers->id,
        'montant_total' => 100,
    ]);

    // Lui associer une ligne de ventilation (la factory en crée mais on en crée une propre)
    // On supprime les lignes factory et on crée une ligne conforme (ventilation)
    $txPointe->lignes()->forceDelete();
    $txPointe->lignes()->create([
        'compte_id' => $this->compteRecette->id,
        'montant' => '100.00',
        'debit' => 0,
        'credit' => 100.00,
    ]);

    // Éditer via TransactionForm
    Livewire::test(TransactionForm::class)
        ->call('edit', $txPointe->id)
        ->set('libelle', 'Libellé mis à jour')
        ->call('save')
        ->assertHasNoErrors();

    $txPointe->refresh();

    // Le statut doit rester Pointe
    expect($txPointe->mode_paiement?->value)->toBe('cheque')
        ->and($txPointe->statut_reglement)->toBe(StatutReglement::Pointe);
});

it('préserve le mode et le statut d’une transaction HelloAsso lors d’une sauvegarde sans changement', function (): void {
    $transaction = app(TransactionService::class)->create([
        'type' => 'recette',
        'date' => dateExercice(),
        'libelle' => 'Don HelloAsso',
        'montant_total' => 100,
        'mode_paiement' => 'virement',
        'compte_id' => $this->compte->id,
        'tiers_id' => $this->tiers->id,
        'reference' => null,
        'notes' => null,
    ], [[
        'compte_id' => $this->compteRecette->id,
        'operation_id' => null,
        'seance' => null,
        'montant' => '100.00',
        'notes' => null,
    ]]);
    $transaction->update(['helloasso_order_id' => 'order-review-task-9']);
    $nombreT2Avant = Transaction::query()
        ->where('journal', JournalComptable::Banque->value)
        ->count();

    Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->assertSet('mode_paiement', 'virement')
        ->call('save')
        ->assertHasNoErrors();

    expect($transaction->fresh()->mode_paiement?->value)->toBe('virement')
        ->and($transaction->fresh()->statut_reglement)->toBe(StatutReglement::Recu)
        ->and(Transaction::query()->where('journal', JournalComptable::Banque->value)->count())->toBe($nombreT2Avant);
});

it('préserve le mode d’un miroir d’extourne lors d’une sauvegarde sans changement', function (): void {
    $transaction = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'date' => dateExercice(),
        'mode_paiement' => 'virement',
        'statut_reglement' => StatutReglement::Recu,
        'compte_id' => $this->compte->id,
        'tiers_id' => $this->tiers->id,
        'type_ecriture' => 'extourne',
    ]);
    $transaction->lignes()->forceDelete();
    $transaction->lignes()->create([
        'compte_id' => $this->compteRecette->id,
        'montant' => '100.00',
        'debit' => 0,
        'credit' => 100,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $transaction->id)
        ->assertSet('mode_paiement', 'virement')
        ->call('save')
        ->assertHasNoErrors();

    expect($transaction->fresh()->mode_paiement?->value)->toBe('virement');
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
        ->set('tiers_id', $this->tiers->id)
        ->set('lignes', [[
            'compte_id' => (string) $this->compteRecette->id,
            'operation_id' => '',
            'seance' => '',
            'montant' => '120.00',
            'notes' => '',
            'piece_jointe_upload' => null,
            'piece_jointe_remove' => false,
        ]])
        ->call('save');

    $tx = Transaction::where('association_id', $this->association->id)
        ->where('libelle', 'Recette encaissée')
        ->firstOrFail();

    expect($tx->statut_reglement->isEncaisse())->toBeTrue();
});
