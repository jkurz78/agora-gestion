<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Livewire\FactureEdit;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Crée une facture manuelle validée avec 1 ligne MontantManuel, annule la facture
 * (ce qui génère Tg extournée + Tm miroir) et retourne [facture annulée, Tg, Tm].
 */
function selecteurCreerFactureAnnuleeAvecMM(
    FactureService $service,
    Tiers $tiers,
    CompteBancaire $compte,
    float $montant = 80.0,
): array {
    $sousCategorie = SousCategorie::factory()->create();

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

    $facture->update(['montant_total' => $montant]);
    $facture->refresh();

    $service->valider($facture);
    $facture->refresh();

    $tg = Transaction::latest('id')->first();

    // Annuler la facture — génère Tg.extournee_at non nul + Tm miroir
    $service->annuler($facture);
    $facture->refresh();
    $tg->refresh();

    // Tm : transaction miroir (la dernière créée après Tg)
    $tm = Transaction::where('id', '!=', $tg->id)
        ->orderByDesc('id')
        ->first();

    return [$facture, $tg, $tm];
}

// ─── BDD §2 #11 — Test 1 : sélecteur exclut la TX origine extournée ──────────

test('le selecteur de FactureEdit n exclut pas la TX origine extournee d une facture precedemment annulee', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    [$f1annulee, $tg, $tm] = selecteurCreerFactureAnnuleeAvecMM(
        $this->service,
        $tiers,
        $this->compte,
        80.0,
    );

    // Préconditions
    expect($tg->extournee_at)->not->toBeNull();
    expect($tm)->not->toBeNull();

    // TX libre du même tiers (doit apparaître dans le sélecteur — sanity check)
    $txLibre = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Recette libre',
        'montant_total' => 50.0,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::EnAttente,
        'tiers_id' => $tiers->id,
        'compte_id' => $this->compte->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $txLibre->id,
        'sous_categorie_id' => null,
        'montant' => 50.0,
    ]);

    // Créer F2 brouillon pour le même tiers
    $f2 = $this->service->creer($tiers->id);

    // Render FactureEdit sur F2 — le sélecteur ne doit pas contenir Tg (TX origine extournée)
    Livewire::test(FactureEdit::class, ['facture' => $f2])
        ->assertViewHas('transactions', fn (Collection $txs) => ! $txs->contains(fn ($tx) => (int) $tx->id === (int) $tg->id));
});

// ─── BDD §2 #11 — Test 2 : sélecteur exclut le miroir d'extourne ─────────────

test('le selecteur de FactureEdit n exclut pas la TX miroir d extourne', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    [$f1annulee, $tg, $tm] = selecteurCreerFactureAnnuleeAvecMM(
        $this->service,
        $tiers,
        $this->compte,
        80.0,
    );

    // Préconditions : Tm est dans extournes.transaction_extourne_id
    $extourne = Extourne::where('transaction_origine_id', $tg->id)->first();
    expect($extourne)->not->toBeNull();
    expect((int) $extourne->transaction_extourne_id)->toBe((int) $tm->id);

    // Créer F2 brouillon pour le même tiers
    $f2 = $this->service->creer($tiers->id);

    // Render FactureEdit sur F2 — le sélecteur ne doit pas contenir Tm (miroir d'extourne)
    Livewire::test(FactureEdit::class, ['facture' => $f2])
        ->assertViewHas('transactions', fn (Collection $txs) => ! $txs->contains(fn ($tx) => (int) $tx->id === (int) $tm->id));
});

// ─── Sanity : TX libre reste présente dans le sélecteur ──────────────────────

test('le selecteur de FactureEdit inclut une TX libre du meme tiers', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    [$f1annulee, $tg, $tm] = selecteurCreerFactureAnnuleeAvecMM(
        $this->service,
        $tiers,
        $this->compte,
        80.0,
    );

    // TX libre du même tiers (doit apparaître dans le sélecteur)
    $txLibre = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Recette libre',
        'montant_total' => 50.0,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::EnAttente,
        'tiers_id' => $tiers->id,
        'compte_id' => $this->compte->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $txLibre->id,
        'sous_categorie_id' => null,
        'montant' => 50.0,
    ]);

    // Créer F2 brouillon pour le même tiers
    $f2 = $this->service->creer($tiers->id);

    // La TX libre doit être présente dans le sélecteur
    Livewire::test(FactureEdit::class, ['facture' => $f2])
        ->assertViewHas('transactions', fn (Collection $txs) => $txs->contains(fn ($tx) => (int) $tx->id === (int) $txLibre->id));
});
