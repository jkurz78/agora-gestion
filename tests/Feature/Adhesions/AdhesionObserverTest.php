<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;

/**
 * Helper: create a Transaction + one explicit ligne without firing observers,
 * so each test controls exactly when the observer fires.
 *
 * We suppress BOTH Transaction and TransactionLigne events during fixture setup:
 * - Transaction::withoutEvents prevents the Transaction created/updated observers
 * - TransactionLigne::withoutEvents prevents the TransactionLigne saved observer
 *   triggered by the factory's afterCreating hook (random lignes)
 */
function createTxWithoutObservers(array $txAttrs, ?int $scId = null): Transaction
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

    if ($scId !== null) {
        // Add the desired ligne WITHOUT firing observer (observer fires separately in tests)
        TransactionLigne::withoutEvents(function () use ($tx, $scId): void {
            TransactionLigne::factory()->create([
                'transaction_id' => $tx->id,
                'sous_categorie_id' => $scId,
            ]);
        });
    }

    return $tx;
}

beforeEach(function (): void {
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
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
        'sous_categorie_id' => $this->sc->id,
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
        'sous_categorie_id' => $this->sc->id,
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
        'sous_categorie_id' => $this->sc->id,
    ]);

    $this->tx->delete();
    expect(Adhesion::count())->toBe(0);

    $this->tx->restore();

    expect(Adhesion::count())->toBe(1);
    expect(Adhesion::withTrashed()->count())->toBe(1);
});

it('créer une transaction recette avec ligne don (pas cotisation) ne crée PAS d\'adhésion', function (): void {
    $scDon = SousCategorie::factory()->pourDons()->create();

    $this->tx = createTxWithoutObservers([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);

    // Adding a don ligne should NOT trigger adhesion creation
    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'sous_categorie_id' => $scDon->id,
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
        'sous_categorie_id' => $this->sc->id,
    ]);

    expect(Adhesion::count())->toBe(1);

    // Touch transaction — fires AdhesionObserver::updated → creerDepuisTransaction (idempotent)
    $this->tx->touch();

    expect(Adhesion::count())->toBe(1);
});

it('transaction avec plusieurs lignes (cotisation + don) ne crée qu\'une seule adhésion', function (): void {
    $scDon = SousCategorie::factory()->pourDons()->create();

    $this->tx = createTxWithoutObservers([
        'tiers_id' => $this->tiers->id,
        'date' => '2025-10-15',
    ]);

    // Add cotisation ligne — creates adhesion
    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'sous_categorie_id' => $this->sc->id,
    ]);

    // Add don ligne — creerDepuisTransaction idempotent: still 1 adhesion
    TransactionLigne::factory()->create([
        'transaction_id' => $this->tx->id,
        'sous_categorie_id' => $scDon->id,
    ]);

    expect(Adhesion::count())->toBe(1);
});
