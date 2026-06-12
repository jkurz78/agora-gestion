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

    // Compte bancaire lié à l'association (requis pour que la TX générée ait un compte_id)
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
 * (ce qui génère Tg en EnAttente), force Tg en Pointe rattachée à R1 verrouillé,
 * et retourne [facture rafraîchie, Tg, R1].
 */
function pointeCreerFactureAvecTgPointee(
    FactureService $service,
    Tiers $tiers,
    SousCategorie $sousCategorie,
    CompteBancaire $compte,
    float $montant = 150.0,
): array {
    $facture = $service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Inscription stage',
        'prix_unitaire' => $montant,
        'quantite' => 1.0,
        'montant' => $montant,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => $montant]);
    $facture->refresh();

    $service->valider($facture);
    $facture->refresh();

    /** @var Transaction $tg */
    $tg = Transaction::latest('id')->first();

    // Créer R1 : rapprochement bancaire de type Bancaire, verrouillé
    $r1 = RapprochementBancaire::factory()->create([
        'association_id' => $facture->association_id,
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
        'statut' => StatutRapprochement::Verrouille,
    ]);

    // Forcer Tg en Pointe, rattachée à R1
    $tg->update([
        'rapprochement_id' => $r1->id,
        'statut_reglement' => StatutReglement::Pointe->value,
    ]);
    $tg->refresh();

    return [$facture, $tg, $r1];
}

// ─── BDD §2 Scénario #2 : MontantManuel Pointé (banque verrouillée) → extourne EnAttente, sans lettrage ─

test('annulation facture MontantManuel Pointe produit extourne EnAttente sans lettrage', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    [$facture, $tg, $r1] = pointeCreerFactureAvecTgPointee(
        $this->service,
        $tiers,
        $sousCategorie,
        $this->compte,
        150.0,
    );

    // Préconditions
    expect($facture->statut)->toBe(StatutFacture::Validee);
    expect((float) $tg->montant_total)->toBe(150.0);
    expect($tg->statut_reglement)->toBe(StatutReglement::Pointe);
    expect((int) $tg->rapprochement_id)->toBe((int) $r1->id);

    // ── Action ────────────────────────────────────────────────────────────────
    $this->service->annuler($facture);

    // ── Assertions facture ────────────────────────────────────────────────────

    $factureFraiche = $facture->fresh();

    expect($factureFraiche->statut)->toBe(StatutFacture::Annulee);
    expect($factureFraiche->numero_avoir)->not->toBeNull();
    expect($factureFraiche->date_annulation)->not->toBeNull();

    // ── Assertions transaction miroir (Tm) : EnAttente, pas de rapprochement ─

    $tm = Transaction::where('id', '!=', $tg->id)
        ->orderByDesc('id')
        ->first();

    expect($tm)->not->toBeNull();
    expect((float) $tm->montant_total)->toBe(-150.0);
    expect($tm->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($tm->rapprochement_id)->toBeNull();

    // ── Aucun nouveau rapprochement de type Lettrage ne doit exister ──────────

    expect(
        RapprochementBancaire::where('type', TypeRapprochement::Lettrage)->count()
    )->toBe(0);

    // ── Tg reste Pointé, rattaché à R1, inchangé ─────────────────────────────

    $tgFrais = $tg->fresh();

    expect($tgFrais->statut_reglement)->toBe(StatutReglement::Pointe);
    expect((int) $tgFrais->rapprochement_id)->toBe((int) $r1->id);

    // ── Tg est bien extournée ─────────────────────────────────────────────────

    expect($tgFrais->extournee_at)->not->toBeNull();

    // ── Entrée extournes créée avec rapprochement_lettrage_id null ────────────

    $extourne = Extourne::first();

    expect($extourne)->not->toBeNull();
    expect((int) $extourne->transaction_origine_id)->toBe((int) $tg->id);
    expect((int) $extourne->transaction_extourne_id)->toBe((int) $tm->id);
    expect($extourne->rapprochement_lettrage_id)->toBeNull();

    // ── Pivot conservé : Tg reste liée à la facture annulée ──────────────────

    $txPivot = $factureFraiche->transactions;
    expect($txPivot->contains(fn ($t) => (int) $t->id === (int) $tg->id))->toBeTrue();
});
