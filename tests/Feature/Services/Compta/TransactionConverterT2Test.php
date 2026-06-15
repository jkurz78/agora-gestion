<?php

declare(strict_types=1);

/**
 * TransactionConverterT2Test
 *
 * Vérifie que la branche « comptant » de TransactionConverter::convertir()
 * produit une structure T1 + T2 séparées (pattern chantier 2b/3b) au lieu
 * du cycle lumped hérité.
 *
 * Cas couverts :
 *   [a] Recette comptant virement     → T1 (411D/706C) + T2 (512X D/411C lettrée)
 *   [b] Recette comptant chèque       → T2 portage = 5112
 *   [c] Recette chèque pointé direct  → T2 portage = 512X (override), rapprochement_id propagé
 *   [d] Dépense comptant virement     → T1 (606D/401C) + T2 (401D/512X C lettrée)
 *   [e] Régression recette en_attente → T1 only (411D/706C), aucune T2
 */

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\TransactionConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPartieDoubleContext;

uses(RefreshDatabase::class);
uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup commun
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    Config::set('compta.use_partie_double', true);

    // Tiers utilisé par tous les tests
    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
    ]);
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée une Transaction recette legacy (equilibree=false, ventilation legacy).
 *
 * La factory TransactionFactory::configure() crée des lignes avec SousCategorie::factory()
 * — ces lignes parasite la conversion (SC sans code_cerfa, compte introuvable).
 * On les supprime toutes et on insère manuellement la seule ligne de ventilation souhaitée.
 */
function makeRecetteLegacy(object $ctx, array $overrides = []): Transaction
{
    $tx = Transaction::factory()->asRecette()->create(array_merge([
        'association_id' => $ctx->association->id,
        'compte_id' => $ctx->compteBancaire->id,
        'mode_paiement' => ModePaiement::Virement,
        'montant_total' => 100.00,
        'statut_reglement' => StatutReglement::Recu,
        'tiers_id' => $ctx->tiers->id,
        'remise_id' => null,
        'rapprochement_id' => null,
        'equilibree' => false,
    ], $overrides));

    // Purger toutes les lignes créées par la factory (elles ont des SC parasites)
    TransactionLigne::where('transaction_id', $tx->id)->delete();

    // Ligne legacy unique : sous_categorie_id set, colonnes PD vides
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $ctx->sc706->id,
        'montant' => 100.00,
        'operation_id' => null,
        'debit' => 0,
        'credit' => 0,
        'compte_id' => null,
        'tiers_id' => null,
    ]);

    return $tx->fresh();
}

/**
 * Crée une Transaction dépense legacy (equilibree=false, ventilation legacy).
 */
function makeDepenseLegacy(object $ctx, array $overrides = []): Transaction
{
    $tx = Transaction::factory()->asDepense()->create(array_merge([
        'association_id' => $ctx->association->id,
        'compte_id' => $ctx->compteBancaire->id,
        'mode_paiement' => ModePaiement::Virement,
        'montant_total' => 80.00,
        'statut_reglement' => StatutReglement::Recu,
        'tiers_id' => $ctx->tiers->id,
        'remise_id' => null,
        'rapprochement_id' => null,
        'equilibree' => false,
    ], $overrides));

    // Purger toutes les lignes créées par la factory
    TransactionLigne::where('transaction_id', $tx->id)->delete();

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $ctx->sc606->id,
        'montant' => 80.00,
        'operation_id' => null,
        'debit' => 0,
        'credit' => 0,
        'compte_id' => null,
        'tiers_id' => null,
    ]);

    return $tx->fresh();
}

// ---------------------------------------------------------------------------
// [a] Recette comptant virement
// ---------------------------------------------------------------------------

it('[a] recette virement produit T1 (411D/706C) + T2 (512XD/411C) lettrées', function () {
    $tx = makeRecetteLegacy($this);

    DB::transaction(fn () => app(TransactionConverter::class)->convertir($tx));

    // T1 marquée équilibrée
    expect($tx->fresh()->equilibree)->toBeTrue();

    $compte411 = Compte::ofNumeroSysteme('411');

    // T1 : doit avoir une ligne 411 débit + une ligne 706 crédit
    $lignesT1 = TransactionLigne::where('transaction_id', $tx->id)
        ->whereNotNull('compte_id')
        ->get();

    $ligne411D = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte411->id && (float) $l->debit > 0);
    $ligne706C = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $this->compte706->id && (float) $l->credit > 0);

    expect($ligne411D)->not->toBeNull('T1 doit avoir une ligne 411 débit');
    expect((float) $ligne411D->debit)->toBe(100.0);
    expect((int) $ligne411D->tiers_id)->toBe((int) $this->tiers->id);

    expect($ligne706C)->not->toBeNull('T1 doit avoir une ligne 706 crédit');
    expect((float) $ligne706C->credit)->toBe(100.0);

    // T2 : une transaction séparée de journal Banque doit exister
    $t2 = Transaction::where('association_id', $this->association->id)
        ->where('id', '!=', $tx->id)
        ->whereNotNull('journal')
        ->first();

    expect($t2)->not->toBeNull('T2 doit exister');

    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->whereNotNull('compte_id')->get();

    $ligne512D = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $this->compte512X->id && (float) $l->debit > 0);
    $ligne411C = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $compte411->id && (float) $l->credit > 0);

    expect($ligne512D)->not->toBeNull('T2 doit avoir une ligne 512X débit');
    expect((float) $ligne512D->debit)->toBe(100.0);

    expect($ligne411C)->not->toBeNull('T2 doit avoir une ligne 411 crédit');
    expect((float) $ligne411C->credit)->toBe(100.0);

    // Lettrage inter-transaction : 411 T1 et 411 T2 partagent le même lettrage_code
    $ligne411T1 = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte411->id);
    expect($ligne411T1->lettrage_code)->not->toBeNull('Ligne 411 T1 doit être lettrée');
    expect($ligne411C->fresh()->lettrage_code)->toBe($ligne411T1->fresh()->lettrage_code);
});

// ---------------------------------------------------------------------------
// [b] Recette comptant chèque (sans remise, sans rapprochement) → portage 5112
// ---------------------------------------------------------------------------

it('[b] recette chèque sans rapprochement produit T2 avec portage 5112', function () {
    $tx = makeRecetteLegacy($this, [
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::Recu,
        'remise_id' => null,
        'rapprochement_id' => null,
    ]);

    DB::transaction(fn () => app(TransactionConverter::class)->convertir($tx));

    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $t2 = Transaction::where('association_id', $this->association->id)
        ->where('id', '!=', $tx->id)
        ->whereNotNull('journal')
        ->first();

    expect($t2)->not->toBeNull('T2 doit exister');

    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->whereNotNull('compte_id')->get();

    $ligne5112D = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $compte5112->id && (float) $l->debit > 0);

    expect($ligne5112D)->not->toBeNull('T2 portage doit être sur 5112 pour un chèque non pointé');
    expect((float) $ligne5112D->debit)->toBe(100.0);

    // Pas sur le 512X bancaire
    $ligne512D = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $this->compte512X->id);
    expect($ligne512D)->toBeNull('T2 ne doit PAS pointer sur 512X pour un chèque non pointé');
});

// ---------------------------------------------------------------------------
// [c] Recette chèque pointé direct (rapprochement_id set, remise_id null) → portage 512X
// ---------------------------------------------------------------------------

it('[c] recette chèque pointé direct produit T2 portage 512X et propage rapprochement_id', function () {
    // Créer un rapprochement bancaire factice pour avoir un rapprochement_id
    $rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
    ]);

    $tx = makeRecetteLegacy($this, [
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::Pointe,
        'remise_id' => null,
        'rapprochement_id' => $rapprochement->id,
    ]);

    DB::transaction(fn () => app(TransactionConverter::class)->convertir($tx));

    $t2 = Transaction::where('association_id', $this->association->id)
        ->where('id', '!=', $tx->id)
        ->whereNotNull('journal')
        ->first();

    expect($t2)->not->toBeNull('T2 doit exister');

    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->whereNotNull('compte_id')->get();

    // Portage override → 512X (pas 5112)
    $ligne512D = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $this->compte512X->id && (float) $l->debit > 0);
    expect($ligne512D)->not->toBeNull('T2 portage doit être 512X pour chèque pointé direct');

    // Pas de 5112
    $compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();
    $ligne5112 = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $compte5112->id);
    expect($ligne5112)->toBeNull('T2 ne doit PAS pointer sur 5112 pour un chèque pointé direct');

    // rapprochement_id propagé sur T2
    expect((int) $t2->fresh()->rapprochement_id)->toBe((int) $rapprochement->id);
});

// ---------------------------------------------------------------------------
// [d] Dépense comptant virement
// ---------------------------------------------------------------------------

it('[d] dépense virement produit T1 (606D/401C) + T2 (401D/512XC) lettrées', function () {
    $tx = makeDepenseLegacy($this);

    DB::transaction(fn () => app(TransactionConverter::class)->convertir($tx));

    expect($tx->fresh()->equilibree)->toBeTrue();

    $compte401 = Compte::ofNumeroSysteme('401');

    $lignesT1 = TransactionLigne::where('transaction_id', $tx->id)
        ->whereNotNull('compte_id')
        ->get();

    $ligne606D = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $this->compte606->id && (float) $l->debit > 0);
    $ligne401C = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte401->id && (float) $l->credit > 0);

    expect($ligne606D)->not->toBeNull('T1 doit avoir une ligne 606 débit');
    expect((float) $ligne606D->debit)->toBe(80.0);

    expect($ligne401C)->not->toBeNull('T1 doit avoir une ligne 401 crédit');
    expect((float) $ligne401C->credit)->toBe(80.0);
    expect((int) $ligne401C->tiers_id)->toBe((int) $this->tiers->id);

    // T2 séparée journal Banque
    $t2 = Transaction::where('association_id', $this->association->id)
        ->where('id', '!=', $tx->id)
        ->whereNotNull('journal')
        ->first();

    expect($t2)->not->toBeNull('T2 doit exister');

    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)->whereNotNull('compte_id')->get();

    $ligne401D = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $compte401->id && (float) $l->debit > 0);
    $ligne512C = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $this->compte512X->id && (float) $l->credit > 0);

    expect($ligne401D)->not->toBeNull('T2 doit avoir une ligne 401 débit');
    expect((float) $ligne401D->debit)->toBe(80.0);

    expect($ligne512C)->not->toBeNull('T2 doit avoir une ligne 512X crédit');
    expect((float) $ligne512C->credit)->toBe(80.0);

    // Lettrage inter-transaction 401
    $ligne401T1 = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte401->id);
    expect($ligne401T1->fresh()->lettrage_code)->not->toBeNull('Ligne 401 T1 doit être lettrée');
    expect($ligne401D->fresh()->lettrage_code)->toBe($ligne401T1->fresh()->lettrage_code);
});

// ---------------------------------------------------------------------------
// [e] Régression : recette en_attente → T1 only, aucune T2
// ---------------------------------------------------------------------------

it('[e] régression recette en_attente ne crée pas de T2', function () {
    $tx = makeRecetteLegacy($this, [
        'statut_reglement' => StatutReglement::EnAttente,
        'mode_paiement' => null,
    ]);

    DB::transaction(fn () => app(TransactionConverter::class)->convertir($tx));

    expect($tx->fresh()->equilibree)->toBeTrue();

    $compte411 = Compte::ofNumeroSysteme('411');

    // T1 a bien une ligne 411 débit
    $lignesT1 = TransactionLigne::where('transaction_id', $tx->id)
        ->whereNotNull('compte_id')
        ->get();

    $ligne411D = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte411->id && (float) $l->debit > 0);
    expect($ligne411D)->not->toBeNull('T1 doit avoir une ligne 411 débit pour en_attente');

    // Aucune T2 (journal Banque) créée
    $countT2 = Transaction::where('association_id', $this->association->id)
        ->where('id', '!=', $tx->id)
        ->whereNotNull('journal')
        ->count();

    expect($countT2)->toBe(0, 'Aucune T2 ne doit être créée pour une recette en_attente');
});
