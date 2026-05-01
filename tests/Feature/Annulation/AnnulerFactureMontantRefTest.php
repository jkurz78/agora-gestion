<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\FactureEdit;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Services\FactureService;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
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
 * Crée une TX recette préexistante (200 €, Recu) avec une TransactionLigne,
 * crée une facture brouillon, la rattache via ajouterTransactions, valide la facture,
 * et retourne [facture rafraîchie, Tref].
 */
function refCreerFactureValideeAvecRef(
    FactureService $service,
    Tiers $tiers,
    CompteBancaire $compte,
    float $montant = 200.0,
): array {
    // Tref préexistante : TX recette Recu pour ce tiers
    $tref = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Paiement HelloAsso',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Virement,
        'statut_reglement' => StatutReglement::Recu,
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
    ]);

    // Créer une TransactionLigne pour Tref (sans utiliser la factory auto-create)
    TransactionLigne::create([
        'transaction_id' => $tref->id,
        'sous_categorie_id' => null,
        'montant' => $montant,
    ]);

    // Facture brouillon
    $facture = $service->creerManuelleVierge($tiers->id);

    // Rattacher Tref à la facture brouillon via ajouterTransactions
    $service->ajouterTransactions($facture, [$tref->id]);
    $facture->refresh();

    // Valider la facture (pas de ligne MontantManuel → pas de TX générée)
    $service->valider($facture);
    $facture->refresh();

    return [$facture, $tref];
}

// ─── BDD §2 Scénario #3 : Montant ref → détachement pivot uniquement ─────────

test('annulation facture avec ligne ref détache pivot sans extourne', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    [$facture, $tref] = refCreerFactureValideeAvecRef(
        $this->service,
        $tiers,
        $this->compte,
        200.0,
    );

    // Préconditions
    expect($facture->statut)->toBe(StatutFacture::Validee);
    expect($facture->transactions->contains(fn ($t) => (int) $t->id === (int) $tref->id))->toBeTrue();
    expect($tref->extournee_at)->toBeNull();

    // ── Action ────────────────────────────────────────────────────────────────
    $this->service->annuler($facture);

    // ── Assertions facture ────────────────────────────────────────────────────

    $factureFraiche = $facture->fresh();
    expect($factureFraiche->statut)->toBe(StatutFacture::Annulee);

    // ── Tref inchangée ────────────────────────────────────────────────────────

    $trefFraiche = $tref->fresh();
    expect($trefFraiche->extournee_at)->toBeNull();
    expect($trefFraiche->statut_reglement)->toBe(StatutReglement::Recu);

    // ── Pivot ne contient plus Tref ───────────────────────────────────────────

    expect(
        $factureFraiche->transactions->contains(fn ($t) => (int) $t->id === (int) $tref->id)
    )->toBeFalse();

    // ── Aucune entrée Extourne pour Tref ──────────────────────────────────────

    expect(
        Extourne::where('transaction_origine_id', $tref->id)->exists()
    )->toBeFalse();
});

// ─── BDD §2 Scénario #4 : Tref détachée redevient rattachable + TX extournée exclue ─

test('tx ref detachee redevient rattachable a une nouvelle facture', function (): void {
    $tiers = Tiers::factory()->create(['pour_recettes' => true]);

    [$f1, $tref] = refCreerFactureValideeAvecRef(
        $this->service,
        $tiers,
        $this->compte,
        200.0,
    );

    // Annuler F1 → Tref détachée
    $this->service->annuler($f1);

    // Créer une TX extournée pour s'assurer qu'elle N'apparaît PAS dans le sélecteur (sanity)
    $txExtournee = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'TX extournée sanity',
        'montant_total' => 50.0,
        'mode_paiement' => ModePaiement::Virement,
        'statut_reglement' => StatutReglement::EnAttente,
        'tiers_id' => $tiers->id,
        'compte_id' => $this->compte->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $txExtournee->id,
        'sous_categorie_id' => null,
        'montant' => 50.0,
    ]);

    // Extourner via la primitive S1
    app(TransactionExtourneService::class)
        ->extourner($txExtournee, ExtournePayload::fromOrigine($txExtournee));

    $txExtournee->refresh();

    // Préconditions : txExtournee est bien extournée
    expect($txExtournee->extournee_at)->not->toBeNull();

    // Créer F2 brouillon pour le même tiers
    $f2 = $this->service->creerManuelleVierge($tiers->id);

    // ── Test Livewire : le sélecteur propose Tref mais pas txExtournee ────────

    Livewire::test(FactureEdit::class, ['facture' => $f2])
        ->assertViewHas('transactions', function ($txs) use ($tref, $txExtournee): bool {
            $trefPresente = $txs->contains(fn ($t) => (int) $t->id === (int) $tref->id);
            $extourneeAbsente = ! $txs->contains(fn ($t) => (int) $t->id === (int) $txExtournee->id);

            return $trefPresente && $extourneeAbsente;
        });
});
