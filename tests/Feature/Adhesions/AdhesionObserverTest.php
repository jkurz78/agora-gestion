<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;

/**
 * Helper: create a Transaction + one explicit ligne without firing observers,
 * so each test controls exactly when the observer fires.
 *
 * We suppress BOTH Transaction and TransactionLigne events during fixture setup:
 * - Transaction::withoutEvents prevents the Transaction created/updated observers
 * - TransactionLigne::withoutEvents prevents the TransactionLigne saved observer
 *   triggered by the factory's afterCreating hook (random lignes)
 */
function createTxWithoutObservers(array $txAttrs, ?int $compteId = null): Transaction
{
    /** @var Transaction $tx */
    $tx = null;

    Transaction::withoutEvents(function () use ($txAttrs, &$tx): void {
        TransactionLigne::withoutEvents(function () use ($txAttrs, &$tx): void {
            $tx = Transaction::factory()->asRecette()->create($txAttrs);
            // Remove auto-created random lignes
            TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
        });
    });

    if ($compteId !== null) {
        // Add the desired ligne WITHOUT firing observer (observer fires separately in tests)
        TransactionLigne::withoutEvents(function () use ($tx, $compteId): void {
            TransactionLigne::factory()->create([
                'transaction_id' => $tx->id,
                'compte_id' => $compteId,
                'montant' => 50.00,
                'debit' => 0,
                'credit' => 50.00,
            ]);
        });
    }

    return $tx;
}

beforeEach(function (): void {
    // DC-10a : compte classe 7 flaggé Cotisation (compte-first).
    $this->sc = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '756AO',
        'intitule' => 'Cotisations',
        'classe' => 7,
        'actif' => true,
    ]);
    $this->sc->usages()->create(['usage' => UsageComptable::Cotisation->value]);
    $this->tiers = Tiers::factory()->create();
});

it('créer une transaction recette avec ligne cotisation crée une adhésion auto', function (): void {
    // Set up tx with no lignes yet (no observer fire)
    $this->tx = createTxWithoutObservers([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);

    expect(Adhesion::count())->toBe(0);

    // Now add cotisation ligne — fires TransactionLigne::saved → AdhesionTransactionLigneObserver
    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'compte_id' => $this->sc->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]);

    expect(Adhesion::count())->toBe(1);
    $adhesion = Adhesion::first();
    expect($adhesion->estGratuite())->toBeFalse();
    expect($adhesion->transaction_id)->toBe($this->tx->id);
    expect($adhesion->tiers_id)->toBe($this->tiers->id);
    expect($adhesion->exercice)->toBe(2025);
});

it('soft-deleter la transaction soft-delete l\'adhésion miroir', function (): void {
    $this->tx = createTxWithoutObservers([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);

    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'compte_id' => $this->sc->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]);

    expect(Adhesion::count())->toBe(1);

    $this->tx->delete();

    expect(Adhesion::count())->toBe(0);
    expect(Adhesion::withTrashed()->count())->toBe(1);
});

it('restore la transaction restore l\'adhésion miroir', function (): void {
    $this->tx = createTxWithoutObservers([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);

    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'compte_id' => $this->sc->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]);

    $this->tx->delete();
    expect(Adhesion::count())->toBe(0);

    $this->tx->restore();

    expect(Adhesion::count())->toBe(1);
    expect(Adhesion::withTrashed()->count())->toBe(1);
});

it('créer une transaction recette avec ligne don (pas cotisation) ne crée PAS d\'adhésion', function (): void {
    $scDon = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '754AO',
        'intitule' => 'Dons',
        'classe' => 7,
        'actif' => true,
    ]);
    $scDon->usages()->create(['usage' => UsageComptable::Don->value]);

    $this->tx = createTxWithoutObservers([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);

    // Adding a don ligne should NOT trigger adhesion creation
    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'compte_id' => $scDon->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]);

    expect(Adhesion::count())->toBe(0);
});

it('mettre à jour la transaction sans changement de cotisation ne duplique pas l\'adhésion', function (): void {
    $this->tx = createTxWithoutObservers([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);

    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'compte_id' => $this->sc->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]);

    expect(Adhesion::count())->toBe(1);

    // Touch transaction — fires AdhesionObserver::updated → creerDepuisTransaction (idempotent)
    $this->tx->touch();

    expect(Adhesion::count())->toBe(1);
});

it('transaction avec plusieurs lignes (cotisation + don) ne crée qu\'une seule adhésion', function (): void {
    $scDon = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '754AO',
        'intitule' => 'Dons',
        'classe' => 7,
        'actif' => true,
    ]);
    $scDon->usages()->create(['usage' => UsageComptable::Don->value]);

    $this->tx = createTxWithoutObservers([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);

    // Add cotisation ligne — creates adhesion
    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'compte_id' => $this->sc->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]);

    // Add don ligne — creerDepuisTransaction idempotent: still 1 adhesion
    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'compte_id' => $scDon->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]);

    expect(Adhesion::count())->toBe(1);
});
