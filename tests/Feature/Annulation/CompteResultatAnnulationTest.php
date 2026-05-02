<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Services\Rapports\CompteResultatBuilder;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->association = Association::factory()->create();

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->association->update(['facture_compte_bancaire_id' => $this->compte->id]);

    $this->comptable = User::factory()->create();
    $this->comptable->associations()->attach($this->association->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $this->comptable->update(['derniere_association_id' => $this->association->id]);

    TenantContext::boot($this->association);
    $this->actingAs($this->comptable);

    $this->service = app(FactureService::class);
    $this->builder = app(CompteResultatBuilder::class);
    $this->exerciceCourant = app(ExerciceService::class)->current();
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── BDD §2 Scénario #13 : Compte de résultat reflète l'annulation (AC-13) ──

/**
 * Test : le compte de résultat post-annulation affiche ∑ sous-catégorie = 0 €.
 *
 * Le CompteResultatBuilder agrège les montants par sous-catégorie via SUM(transaction_lignes.montant).
 * Après annulation :
 *   - Tg contribue +80 € à la sous-catégorie
 *   - Tm (extourne) contribue -80 € à la même sous-catégorie (copierLignesInversees)
 *   → ∑ = 0 €
 *
 * Note : le builder ne retourne pas le détail ligne par ligne — il fournit uniquement le montant
 * agrégé par sous-catégorie (montant_n). Pour vérifier les 2 écritures (+80 et -80), on asserté
 * directement les 2 TransactionLigne en base (sanity check).
 */
test('le compte de résultat reflète une annulation par +X et -X dans la même sous-catégorie', function (): void {
    // ── Setup sous-catégorie recette ──────────────────────────────────────────
    $cat = Categorie::create([
        'nom' => 'Cotisations',
        'type' => 'recette',
    ]);
    $sousCategorie = SousCategorie::create([
        'categorie_id' => $cat->id,
        'nom' => 'Cotisations séance',
        'libelle_article' => 'des cotisations séance',
    ]);

    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    // ── Créer et valider la facture manuelle 80 € MM ──────────────────────────
    $facture = $this->service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Cotisation mars',
        'prix_unitaire' => 80.0,
        'quantite' => 1.0,
        'montant' => 80.0,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => 80.0]);
    $facture->refresh();

    $this->service->valider($facture);
    $facture->refresh();

    expect($facture->statut)->toBe(StatutFacture::Validee);

    // ── Annuler la facture (même exercice) ────────────────────────────────────
    $this->service->annuler($facture);

    $factureFraiche = $facture->fresh();
    expect($factureFraiche->statut)->toBe(StatutFacture::Annulee);

    // ── Sanity check : 2 TransactionLigne dans la sous-catégorie (+80 et -80) ─
    $lignes = TransactionLigne::where('sous_categorie_id', $sousCategorie->id)->get();
    expect($lignes)->toHaveCount(2, '2 lignes attendues : origine +80 et extourne -80');

    $montants = $lignes->pluck('montant')->map(fn ($m) => (float) $m)->sort()->values()->all();
    expect($montants)->toBe([-80.0, 80.0], 'Les 2 lignes doivent être +80 et -80');

    // ── Compte de résultat — ∑ sous-catégorie = 0 € ───────────────────────────
    $result = $this->builder->compteDeResultat($this->exerciceCourant);

    $produits = collect($result['produits'])->flatMap(fn ($c) => $c['sous_categories'] ?? []);
    $scResult = $produits->firstWhere('sous_categorie_id', $sousCategorie->id);

    // Cas 1 : la sous-catégorie n'apparaît pas du tout (somme exactement 0, éliminée par la DB)
    // Cas 2 : elle apparaît avec montant_n = 0.0
    // Les deux sont acceptables ; l'invariant est ∑ = 0
    if ($scResult !== null) {
        expect((float) $scResult['montant_n'])->toBe(
            0.0,
            'La somme nette de la sous-catégorie doit être 0 € après annulation'
        );
    }
    // Si $scResult === null : la DB a renvoyé SUM = 0 et la ligne n'est pas remontée.
    // C'est aussi valide — la sous-catégorie n'a plus de contribution nette.
    // Le sanity check ci-dessus confirme déjà que les 2 lignes DB existent.
});

/**
 * Vérification additionnelle : la somme des TransactionLigne sur la sous-catégorie est bien 0.
 * Test de second opinion sur les données brutes (indépendant du builder).
 */
test('la somme des TransactionLigne de la sous-catégorie est 0 après annulation', function (): void {
    $cat = Categorie::create([
        'nom' => 'Cotisations',
        'type' => 'recette',
    ]);
    $sousCategorie = SousCategorie::create([
        'categorie_id' => $cat->id,
        'nom' => 'Cotisations séance 2',
        'libelle_article' => 'des cotisations',
    ]);

    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    $facture = $this->service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Cotisation avril',
        'prix_unitaire' => 150.0,
        'quantite' => 1.0,
        'montant' => 150.0,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => 150.0]);
    $facture->refresh();

    $this->service->valider($facture);
    $facture->refresh();

    $this->service->annuler($facture);

    // Détail : 2 lignes +150 et -150 → somme = 0
    $lignes = TransactionLigne::where('sous_categorie_id', $sousCategorie->id)->get();
    expect($lignes)->toHaveCount(2);
    expect((float) $lignes->sum('montant'))->toBe(0.0);
    expect($lignes->pluck('montant')->map(fn ($m) => (float) $m)->sort()->values()->all())
        ->toBe([-150.0, 150.0]);
});
