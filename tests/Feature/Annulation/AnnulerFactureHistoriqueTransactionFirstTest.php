<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
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
    $this->exerciceCourant = app(ExerciceService::class)->current();
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── AC-23 : non-régression v2.5.4 — mode transaction-first sans ligne MM ────

test('il annule une facture historique transaction-first sans ligne MM sans extourne', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    // Mode v2.5.4 transaction-first :
    // Tref : TX recette préexistante Recu avec sa TransactionLigne
    $tref = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Paiement HelloAsso',
        'montant_total' => 80.0,
        'mode_paiement' => ModePaiement::Virement,
        'statut_reglement' => StatutReglement::Recu,
        'tiers_id' => $tiers->id,
        'compte_id' => $this->compte->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tref->id,
        'sous_categorie_id' => null,
        'montant' => 80.0,
    ]);

    // Créer facture brouillon et rattacher Tref via ajouterTransactions
    $facture = $this->service->creer($tiers->id);
    $this->service->ajouterTransactions($facture, [$tref->id]);
    $facture->refresh();

    // Valider la facture (0 ligne MontantManuel, uniquement la ligne ref)
    $this->service->valider($facture);
    $facture->refresh();

    // Préconditions : facture validée, 0 ligne MontantManuel
    expect($facture->statut)->toBe(StatutFacture::Validee);
    $lignesMM = $facture->lignes()->where('type', 'montant_manuel')->get();
    expect($lignesMM)->toHaveCount(0);

    // ── Action ────────────────────────────────────────────────────────────────
    $this->service->annuler($facture);

    // ── Assertions facture ────────────────────────────────────────────────────
    $factureFraiche = $facture->fresh();

    expect($factureFraiche->statut)->toBe(StatutFacture::Annulee);
    expect($factureFraiche->numero_avoir)->not->toBeNull();
    expect($factureFraiche->numero_avoir)->toBe(
        sprintf('AV-%d-0001', $this->exerciceCourant)
    );
    expect($factureFraiche->date_annulation)->not->toBeNull();

    // ── Aucune extourne créée (0 ligne MM) ───────────────────────────────────
    expect(Extourne::count())->toBe(0);

    // ── Tref inchangée ───────────────────────────────────────────────────────
    $trefFraiche = $tref->fresh();
    expect($trefFraiche->extournee_at)->toBeNull();
    expect($trefFraiche->statut_reglement)->toBe(StatutReglement::Recu);

    // ── Pivot vidé : Tref détachée de la facture ─────────────────────────────
    expect($factureFraiche->transactions->count())->toBe(0);

    // ── Aucun rapprochement de type Lettrage créé ────────────────────────────
    $lettrage = RapprochementBancaire::where('type', TypeRapprochement::Lettrage)->first();
    expect($lettrage)->toBeNull();
});
