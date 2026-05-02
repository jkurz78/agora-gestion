<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
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

// ─── BDD §2 #10 — État pathologique : TX MontantManuel déjà extournée hors flux

test('il refuse l annulation si une TX MontantManuel a deja ete extournee hors flux etat pathologique', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    // Créer facture validée avec 1 ligne MontantManuel (Tg générée)
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

    // Tg : transaction générée par la validation
    $tg = Transaction::latest('id')->first();
    expect($tg)->not->toBeNull();

    // Préconditions : facture validée, Tg saine
    expect($facture->statut)->toBe(StatutFacture::Validee);
    expect($tg->extournee_at)->toBeNull();

    // ── Simuler l'état pathologique : forcer extournee_at sur Tg SANS passer par S1 ──
    // (ne crée PAS d'entrée Extourne — juste le flag brut, état incohérent)
    $tg->update(['extournee_at' => now()]);
    $tg->refresh();
    expect($tg->extournee_at)->not->toBeNull();

    // ── Action : tenter d'annuler → doit lever RuntimeException ─────────────
    expect(fn () => $this->service->annuler($facture))
        ->toThrow(RuntimeException::class, 'déjà été annulée');

    // ── La facture reste Validee (pré-validation avant flip statut) ───────────
    $factureFraiche = $facture->fresh();
    expect($factureFraiche->statut)->toBe(StatutFacture::Validee);
    expect($factureFraiche->numero_avoir)->toBeNull();

    // ── Aucune nouvelle Extourne créée ────────────────────────────────────────
    expect(Extourne::count())->toBe(0);
});

// ─── Vérification du message d'erreur complet ────────────────────────────────

test('le message d erreur contient Etat incoherent', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    $facture = $this->service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Stage été',
        'prix_unitaire' => 120.0,
        'quantite' => 1.0,
        'montant' => 120.0,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => 120.0]);
    $facture->refresh();

    $this->service->valider($facture);
    $facture->refresh();

    $tg = Transaction::latest('id')->first();
    $tg->update(['extournee_at' => now()]);

    try {
        $this->service->annuler($facture);
        $this->fail('RuntimeException attendue non levée');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('déjà été annulée');
        expect($e->getMessage())->toContain('État incohérent');
    }
});
