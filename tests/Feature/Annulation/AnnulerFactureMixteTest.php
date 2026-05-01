<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
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

// ─── Helper : crée une facture mixte (1 MM + 1 ref) validée ─────────────────

/**
 * Crée une facture brouillon avec :
 *   - 1 ligne MontantManuel "Stage avril" 100 € (sous-catégorie recette donnée)
 *   - 1 TX recette préexistante Tref 50 € Recu rattachée via ajouterTransactions
 * La valide (ce qui génère Tg EnAttente pour la ligne MM).
 * Retourne [facture rafraîchie, Tg, Tref].
 *
 * @return array{Facture, Transaction, Transaction}
 */
function mixteCreerFactureValidee(
    FactureService $service,
    Tiers $tiers,
    SousCategorie $sousCategorie,
    CompteBancaire $compte,
): array {
    // Tref préexistante 50 € Recu
    $tref = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Paiement ref préexistant',
        'montant_total' => 50.0,
        'mode_paiement' => ModePaiement::Virement,
        'statut_reglement' => StatutReglement::Recu,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tref->id,
        'sous_categorie_id' => null,
        'montant' => 50.0,
    ]);

    // Facture brouillon vierge
    $facture = $service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    // Ligne MontantManuel 100 €
    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Stage avril',
        'prix_unitaire' => 100.0,
        'quantite' => 1.0,
        'montant' => 100.0,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => 150.0]);
    $facture->refresh();

    // Rattacher Tref via ajouterTransactions (ajoute la ligne de type Montant)
    $service->ajouterTransactions($facture, [$tref->id]);
    $facture->refresh();

    // Valider → génère Tg pour la ligne MM
    $service->valider($facture);
    $facture->refresh();

    // Tg est la dernière TX créée (générée par valider)
    $tg = Transaction::where('id', '!=', $tref->id)
        ->orderByDesc('id')
        ->first();

    return [$facture, $tg, $tref];
}

// ─── BDD §2 Scénario #5 : cas mixte MM + ref ─────────────────────────────────

test('annulation facture mixte extourne MM uniquement et detache pivot ref', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    [$facture, $tg, $tref] = mixteCreerFactureValidee(
        $this->service,
        $tiers,
        $sousCategorie,
        $this->compte,
    );

    // ── Préconditions ─────────────────────────────────────────────────────────

    expect($facture->statut)->toBe(StatutFacture::Validee);

    // Tg est EnAttente (générée par valider depuis la ligne MM)
    expect($tg->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($tg->extournee_at)->toBeNull();

    // Tref est Recu (préexistante, référencée)
    expect($tref->statut_reglement)->toBe(StatutReglement::Recu);
    expect($tref->extournee_at)->toBeNull();

    // Pivot contient Tg ET Tref avant annulation
    $txAvant = $facture->fresh()->transactions;
    expect($txAvant->contains(fn ($t) => (int) $t->id === (int) $tg->id))->toBeTrue();
    expect($txAvant->contains(fn ($t) => (int) $t->id === (int) $tref->id))->toBeTrue();

    // ── Action ────────────────────────────────────────────────────────────────

    $this->service->annuler($facture);

    // ── Assertions facture ────────────────────────────────────────────────────

    $factureFraiche = $facture->fresh();
    expect($factureFraiche->statut)->toBe(StatutFacture::Annulee);

    // ── Extourne créée pour Tg uniquement ────────────────────────────────────

    expect(Extourne::where('transaction_origine_id', $tg->id)->exists())->toBeTrue();
    expect(Extourne::where('transaction_origine_id', $tref->id)->exists())->toBeFalse();

    // ── Lettrage automatique créé pour Tg (EnAttente → Pointe) ───────────────

    $lettrage = RapprochementBancaire::where('type', TypeRapprochement::Lettrage)->first();
    expect($lettrage)->not->toBeNull();

    $tgFrais = $tg->fresh();
    expect($tgFrais->extournee_at)->not->toBeNull();
    expect($tgFrais->statut_reglement)->toBe(StatutReglement::Pointe);

    // ── Tref inchangée ────────────────────────────────────────────────────────

    $trefFrais = $tref->fresh();
    expect($trefFrais->extournee_at)->toBeNull();
    expect($trefFrais->statut_reglement)->toBe(StatutReglement::Recu);

    // ── Pivot après annulation : Tg conservée, Tref détachée ─────────────────

    $txApres = $factureFraiche->transactions;
    expect($txApres->contains(fn ($t) => (int) $t->id === (int) $tg->id))->toBeTrue();
    expect($txApres->contains(fn ($t) => (int) $t->id === (int) $tref->id))->toBeFalse();

    // ── Helpers disjoints après annulation ────────────────────────────────────
    // transactionsGenereesParLignesManuelles() → [Tg] (pivot toujours présent)
    // transactionsReferencees() → [] (Tref détachée du pivot)

    $generees = $factureFraiche->transactionsGenereesParLignesManuelles();
    $referencees = $factureFraiche->transactionsReferencees();

    expect($generees->contains(fn ($t) => (int) $t->id === (int) $tg->id))->toBeTrue();
    expect($referencees->isEmpty())->toBeTrue();
});
