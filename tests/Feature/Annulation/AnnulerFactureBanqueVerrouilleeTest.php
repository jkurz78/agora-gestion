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

// ─── BDD §2 Scénario #6 : banque verrouillée — le guard isLockedByRapprochement est supprimé ─

/**
 * Documente le nouveau comportement S2 :
 * - Avant S2 (v2.5.4) : annuler($facture) levait RuntimeException "rapprochée en banque"
 *   si une TX liée avait rapprochement_id non null (guard isLockedByRapprochement).
 * - Après S2 : le guard est supprimé. La primitive S1 gère le cas via une extourne EnAttente
 *   (aucun lettrage automatique car la TX est déjà Pointé).
 *   L'extourne -X € apparaît dans les transactions à pointer du compte.
 *
 * Ce test vérifie explicitement l'ABSENCE de l'exception legacy, et que le flux
 * produit bien une extourne EnAttente sans lettrage.
 */
test('il n\'echoue plus quand une transaction MontantManuel est pointée banque verrouillée', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);
    $sousCategorie = SousCategorie::factory()->create();

    // Créer et valider une facture MontantManuel (génère Tg en EnAttente)
    $facture = $this->service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture->refresh();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Inscription stage',
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

    /** @var Transaction $tg */
    $tg = Transaction::latest('id')->first();

    // Créer R1 : rapprochement bancaire de type Bancaire, verrouillé
    $r1 = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'type' => TypeRapprochement::Bancaire,
        'statut' => StatutRapprochement::Verrouille,
    ]);

    // Forcer Tg en Pointe, rattachée à R1 verrouillé (simule un encaissement déjà rapproché)
    $tg->update([
        'rapprochement_id' => $r1->id,
        'statut_reglement' => StatutReglement::Pointe->value,
    ]);
    $tg->refresh();

    // Préconditions
    expect($facture->statut)->toBe(StatutFacture::Validee);
    expect($tg->statut_reglement)->toBe(StatutReglement::Pointe);
    expect((int) $tg->rapprochement_id)->toBe((int) $r1->id);

    // ── Action — NE DOIT PAS lever l'exception legacy "rapprochée en banque" ─

    // Comportement S2 : aucune exception. La primitive S1 gère le cas via extourne EnAttente.
    expect(fn () => $this->service->annuler($facture))->not->toThrow(
        RuntimeException::class,
        'rapprochée en banque',
    );

    // ── Assertions facture ────────────────────────────────────────────────────

    $factureFraiche = $facture->fresh();

    expect($factureFraiche->statut)->toBe(StatutFacture::Annulee);
    expect($factureFraiche->numero_avoir)->not->toBeNull();

    // ── Assertions Tm : extourne EnAttente (pas de lettrage) ─────────────────

    $tm = Transaction::where('id', '!=', $tg->id)
        ->orderByDesc('id')
        ->first();

    expect($tm)->not->toBeNull();
    expect((float) $tm->montant_total)->toBe(-150.0);
    expect($tm->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($tm->rapprochement_id)->toBeNull();

    // ── Tg reste Pointée, rattachée à R1, inchangée ───────────────────────────

    $tgFrais = $tg->fresh();

    expect($tgFrais->statut_reglement)->toBe(StatutReglement::Pointe);
    expect((int) $tgFrais->rapprochement_id)->toBe((int) $r1->id);

    // ── Aucun lettrage automatique ────────────────────────────────────────────

    expect(
        RapprochementBancaire::where('type', TypeRapprochement::Lettrage)->count()
    )->toBe(0);
});
