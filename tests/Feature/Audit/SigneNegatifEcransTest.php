<?php

declare(strict_types=1);

/**
 * Audit signe négatif — Step 4 : tests de robustesse des écrans Livewire.
 *
 * Vérifie que chaque écran de listing rend correctement en présence de
 * transactions à montant négatif insérées directement en base.
 * Inclut le test critique du filtre "Créances à recevoir" qui doit exclure
 * les montants négatifs (une extourne future ne constitue pas une créance).
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.3
 */

use App\Enums\StatutExercice;
use App\Enums\StatutRapprochement;
use App\Livewire\Dashboard;
use App\Livewire\RapprochementList;
use App\Livewire\TiersTransactions;
use App\Livewire\TransactionCompteList;
use App\Livewire\TransactionUniverselle;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;
use Tests\Support\Concerns\MakesAuditTransactions;

uses(MakesAuditTransactions::class);

// ── Fixtures partagées ────────────────────────────────────────────────────────

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    // Catégorie / sous-catégorie de recette
    $this->categorie = Categorie::factory()->create(['association_id' => $this->association->id]);
    $this->sc = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'association_id' => $this->association->id,
    ]);

    // Compte bancaire
    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'solde_initial' => 0.0,
    ]);

    // Exercice 2025 ouvert, session active
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
});

// ── Test 1 : TransactionUniverselle ──────────────────────────────────────────

it('transaction_universelle_render_avec_negatifs', function () {
    // Insérer une recette à -80 € directement en base (future extourne)
    $this->makeAuditTransaction('recette', -80.0, $this->sc, $this->compte, 2025);

    // Le composant doit rendre sans erreur et lister la transaction négative
    Livewire::test(TransactionUniverselle::class, [
        'pageTitle' => 'Toutes les transactions',
    ])
        ->assertOk()
        ->assertSee('-80'); // le montant négatif est affiché
});

// ── Test 2 : TransactionCompteList ───────────────────────────────────────────

it('transaction_compte_list_render_avec_negatifs', function () {
    // Insérer une recette à -80 € sur le compte
    $this->makeAuditTransaction('recette', -80.0, $this->sc, $this->compte, 2025);

    // Le composant doit rendre sans erreur et afficher la tx dans le compte
    $component = Livewire::test(TransactionCompteList::class);
    // Sélectionner le compte
    $component->set('compteId', $this->compte->id)
        ->assertOk()
        ->assertSee('-80');
});

// ── Test 3 : TiersTransactions ───────────────────────────────────────────────

it('tiers_transactions_render_avec_negatifs', function () {
    // Créer un tiers et lui associer une transaction recette à -80 €
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    // Insérer manuellement la transaction pour ce tiers
    $tx = Transaction::create([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => '2025-10-15',
        'libelle' => 'Audit test recette -80',
        'montant_total' => -80.0,
        'mode_paiement' => 'virement',
        'compte_id' => $this->compte->id,
        'statut_reglement' => 'en_attente',
        'tiers_id' => $tiers->id,
        'saisi_par' => $this->user->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
        'montant' => -80.0,
    ]);

    Livewire::test(TiersTransactions::class, [
        'tiersId' => $tiers->id,
    ])
        ->assertOk()
        ->assertSee('-80');
});

// ── Test 4 : Créances à recevoir — filtre montant > 0 ────────────────────────

it('creances_a_recevoir_exclut_montants_negatifs', function () {
    // Insérer 2 tx recette EnAttente : +100 et -50
    $txPositif = Transaction::create([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => '2025-10-15',
        'libelle' => 'Créance positive',
        'montant_total' => 100.0,
        'mode_paiement' => 'virement',
        'compte_id' => $this->compte->id,
        'statut_reglement' => 'en_attente',
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $txPositif->id,
        'sous_categorie_id' => $this->sc->id,
        'montant' => 100.0,
    ]);

    $txNegatif = Transaction::create([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => '2025-10-20',
        'libelle' => 'Créance négative (extourne)',
        'montant_total' => -50.0,
        'mode_paiement' => 'virement',
        'compte_id' => $this->compte->id,
        'statut_reglement' => 'en_attente',
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $txNegatif->id,
        'sous_categorie_id' => $this->sc->id,
        'montant' => -50.0,
    ]);

    // La vue "Créances à recevoir" utilise TransactionUniverselle + filterStatut = 'en_attente'
    Livewire::test(TransactionUniverselle::class, [
        'lockedTypes' => ['recette'],
    ])
        ->set('filterStatut', 'en_attente')
        ->assertOk()
        // La créance positive (+100) doit apparaître
        ->assertSee('100')
        // La créance négative (-50) NE doit PAS apparaître dans cette vue
        ->assertDontSee('Créance négative (extourne)');
});

// ── Test 5 : Dashboard ────────────────────────────────────────────────────────

it('dashboard_render_avec_negatifs', function () {
    // Dataset mixte : +150 recette, -80 recette, +50 dépense
    $this->makeAuditTransaction('recette', 150.0, $this->sc, $this->compte, 2025);
    $this->makeAuditTransaction('recette', -80.0, $this->sc, $this->compte, 2025);

    // Sous-catégorie dépense
    $scDep = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
        'association_id' => $this->association->id,
    ]);
    $this->makeAuditTransaction('depense', 50.0, $scDep, $this->compte, 2025);

    Livewire::test(Dashboard::class)
        ->assertOk()
        // totalRecettes = 150 + (-80) = 70
        ->assertSee('70')
        // totalDepenses = 50
        ->assertSee('50');
});

// ── Test 6 : RapprochementList ────────────────────────────────────────────────

it('liste_rapprochements_bancaires_render_avec_negatifs', function () {
    // Insérer une tx recette négative pointée dans un rapprochement
    $rapprochement = RapprochementBancaire::create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'date_fin' => '2025-11-30',
        'solde_ouverture' => 500.0,
        'solde_fin' => 420.0,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => $this->user->id,
    ]);

    $this->makeAuditTransaction('recette', -80.0, $this->sc, $this->compte, 2025, $rapprochement);

    // Le composant liste de rapprochements doit rendre sans erreur
    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->assertOk()
        // Le rapprochement doit être listé
        ->assertSee('30/11/2025');
});
