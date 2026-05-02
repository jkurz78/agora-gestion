<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeRapprochement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\RapprochementBancaire;
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

    // Un compte bancaire lié à l'association pour que les TX générées aient un compte_id
    // (requis par RapprochementBancaire.compte_id NOT NULL lors du lettrage automatique)
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

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Crée une facture manuelle avec 1 ligne MontantManuel, la valide via le service
 * (ce qui génère Tg en EnAttente), et retourne [facture rafraîchie, Tg].
 */
function enAttenteCreerFactureValideeAvecMontantManuel(
    FactureService $service,
    Tiers $tiers,
    SousCategorie $sousCategorie,
    float $montant = 80.0,
): array {
    $facture = $service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Cotisation mars',
        'prix_unitaire' => $montant,
        'quantite' => 1.0,
        'montant' => $montant,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    // Recalcule le montant total sur la facture brouillon
    $facture->update(['montant_total' => $montant]);
    $facture->refresh();

    $service->valider($facture);
    $facture->refresh();

    $tg = Transaction::latest('id')->first();

    return [$facture, $tg];
}

// ─── BDD §2 Scénario #1 : MontantManuel EnAttente → extourne + lettrage auto ─

test('annulation facture MontantManuel EnAttente produit extourne et lettrage automatique', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    [$facture, $tg] = enAttenteCreerFactureValideeAvecMontantManuel(
        $this->service,
        $tiers,
        $sousCategorie,
        80.0,
    );

    // Préconditions
    expect($facture->statut)->toBe(StatutFacture::Validee);
    expect((float) $tg->montant_total)->toBe(80.0);
    expect($tg->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($tg->rapprochement_id)->toBeNull();

    $numeroFacture = $facture->numero;

    // ── Action ────────────────────────────────────────────────────────────────
    $this->service->annuler($facture);

    // ── Assertions facture ────────────────────────────────────────────────────

    $factureFraiche = $facture->fresh();

    expect($factureFraiche->statut)->toBe(StatutFacture::Annulee);
    expect($factureFraiche->numero_avoir)->toBe(
        sprintf('AV-%d-0001', $this->exerciceCourant)
    );
    expect($factureFraiche->date_annulation)->not->toBeNull();
    expect($factureFraiche->date_annulation->toDateString())->toBe(now()->toDateString());

    // ── Assertions transaction origine (Tg) ──────────────────────────────────

    $tgFrais = $tg->fresh();

    expect($tgFrais->extournee_at)->not->toBeNull();
    expect($tgFrais->statut_reglement)->toBe(StatutReglement::Pointe);
    expect($tgFrais->rapprochement_id)->not->toBeNull();

    // ── Assertions transaction miroir (Tm) ───────────────────────────────────

    // Tm est la dernière transaction créée
    $tm = Transaction::where('id', '!=', $tg->id)
        ->orderByDesc('id')
        ->first();

    expect($tm)->not->toBeNull();
    expect((float) $tm->montant_total)->toBe(-80.0);
    expect($tm->libelle)->toBe("Annulation - Facture {$numeroFacture}");
    expect($tm->statut_reglement)->toBe(StatutReglement::Pointe);

    // ── Assertion rapprochement de type Lettrage ──────────────────────────────

    $lettrage = RapprochementBancaire::where('type', TypeRapprochement::Lettrage)->first();

    expect($lettrage)->not->toBeNull();
    expect($lettrage->statut)->toBe(StatutRapprochement::Verrouille);

    // Tg et Tm sont tous les deux liés à ce lettrage
    expect((int) $tgFrais->rapprochement_id)->toBe((int) $lettrage->id);
    expect((int) $tm->rapprochement_id)->toBe((int) $lettrage->id);

    // ── Assertion entrée extournes ────────────────────────────────────────────

    $extourne = Extourne::first();

    expect($extourne)->not->toBeNull();
    expect((int) $extourne->transaction_origine_id)->toBe((int) $tg->id);
    expect((int) $extourne->transaction_extourne_id)->toBe((int) $tm->id);
    expect($extourne->rapprochement_lettrage_id)->not->toBeNull();
    expect((int) $extourne->rapprochement_lettrage_id)->toBe((int) $lettrage->id);

    // ── Assertion pivot facture_transaction conservé pour MontantManuel ───────

    $txPivot = $factureFraiche->transactions;
    expect($txPivot->contains(fn ($t) => (int) $t->id === (int) $tg->id))->toBeTrue();
});
