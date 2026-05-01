<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Helpers locaux ──────────────────────────────────────────────────────────

function classifCreateTiers(): Tiers
{
    return Tiers::factory()->create();
}

function classifCreateSousCategorie(): SousCategorie
{
    return SousCategorie::factory()->create();
}

/**
 * Crée une facture brouillon vierge avec 1 ligne MontantManuel, puis la valide.
 * Retourne [facture rafraîchie, transaction générée].
 */
function classifCreerFactureAvecMontantManuel(
    FactureService $service,
    Tiers $tiers,
    SousCategorie $sousCategorie,
    float $montant = 80.0,
): array {
    $facture = $service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture = $facture->fresh();

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel,
        'libelle' => 'Prestation test',
        'prix_unitaire' => $montant,
        'quantite' => 1.0,
        'montant' => $montant,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $sousCategorie->id,
        'ordre' => 1,
    ]);

    $facture->update(['montant_total' => $montant]);
    $facture = $facture->fresh();

    $service->valider($facture);
    $facture->refresh();

    $tg = Transaction::latest('id')->first();

    return [$facture, $tg];
}

/**
 * Crée une transaction recette préexistante + une facture brouillon ref (ligne Montant), puis la valide.
 * Retourne [facture rafraîchie, transaction ref].
 */
function classifCreerFactureAvecRef(
    FactureService $service,
    Tiers $tiers,
    float $montant = 200.0,
): array {
    $tref = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'tiers_id' => $tiers->id,
        'montant_total' => $montant,
        'statut_reglement' => StatutReglement::Recu->value,
        'mode_paiement' => ModePaiement::Virement->value,
    ]);

    // Ajoute une TransactionLigne à tref
    $tl = TransactionLigne::create([
        'transaction_id' => $tref->id,
        'sous_categorie_id' => null,
        'montant' => $montant,
    ]);

    $facture = $service->creer($tiers->id);
    $service->ajouterTransactions($facture, [$tref->id]);
    $facture->refresh();

    $service->valider($facture);
    $facture->refresh();

    return [$facture, $tref];
}

// ─── Setup ───────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->user->update(['derniere_association_id' => $this->association->id]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->service = app(FactureService::class);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ─── Tests helper transactionsGenereesParLignesManuelles ─────────────────────

test('transactionsGenereesParLignesManuelles_retourne_tx_des_lignes_MontantManuel', function (): void {
    $tiers = classifCreateTiers();
    $sousCategorie = classifCreateSousCategorie();

    [$facture, $tg] = classifCreerFactureAvecMontantManuel($this->service, $tiers, $sousCategorie);

    $result = $facture->transactionsGenereesParLignesManuelles();

    expect($result)->toHaveCount(1)
        ->and((int) $result->first()->id)->toBe((int) $tg->id);
});

test('transactionsGenereesParLignesManuelles_ignore_tx_referencees', function (): void {
    $tiers = classifCreateTiers();

    [$facture, $tref] = classifCreerFactureAvecRef($this->service, $tiers);

    $result = $facture->transactionsGenereesParLignesManuelles();

    expect($result)->toHaveCount(0);
});

// ─── Tests helper transactionsReferencees ────────────────────────────────────

test('transactionsReferencees_retourne_tx_des_lignes_Montant_ref', function (): void {
    $tiers = classifCreateTiers();

    [$facture, $tref] = classifCreerFactureAvecRef($this->service, $tiers);

    $result = $facture->transactionsReferencees();

    expect($result)->toHaveCount(1)
        ->and((int) $result->first()->id)->toBe((int) $tref->id);
});

test('transactionsReferencees_ignore_tx_generees', function (): void {
    $tiers = classifCreateTiers();
    $sousCategorie = classifCreateSousCategorie();

    [$facture, $tg] = classifCreerFactureAvecMontantManuel($this->service, $tiers, $sousCategorie);

    $result = $facture->transactionsReferencees();

    expect($result)->toHaveCount(0);
});

// ─── Test helper disjonction sur facture mixte ────────────────────────────────

test('helpers_disjoints_sur_facture_mixte', function (): void {
    $tiers = classifCreateTiers();
    $sousCategorie = classifCreateSousCategorie();

    // Crée transaction préexistante (ref)
    $tref = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'tiers_id' => $tiers->id,
        'montant_total' => 50.0,
        'statut_reglement' => StatutReglement::Recu->value,
        'mode_paiement' => ModePaiement::Virement->value,
    ]);
    $tl = TransactionLigne::create([
        'transaction_id' => $tref->id,
        'sous_categorie_id' => null,
        'montant' => 50.0,
    ]);

    // Facture mixte : 1 MM + 1 ref
    $facture = $this->service->creerManuelleVierge($tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);
    $facture = $facture->fresh();

    // Ajoute ligne MontantManuel
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

    // Ajoute transaction ref
    $this->service->ajouterTransactions($facture, [$tref->id]);
    $facture->refresh();
    $facture->update(['montant_total' => 150.0]);
    $facture = $facture->fresh();

    $this->service->valider($facture);
    $facture->refresh();

    $tg = Transaction::where('montant_total', 100.0)->latest('id')->first();

    $generees = $facture->transactionsGenereesParLignesManuelles();
    $referencees = $facture->transactionsReferencees();

    // Intersection vide
    $intersection = $generees->intersect($referencees);
    expect($intersection)->toHaveCount(0);

    // Union = les deux
    $union = $generees->merge($referencees);
    expect($union)->toHaveCount(2);
    expect($union->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([(int) $tg->id, (int) $tref->id])->sort()->values()->all());
});
