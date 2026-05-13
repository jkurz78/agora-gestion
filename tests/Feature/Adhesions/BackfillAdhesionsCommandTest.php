<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Helper local : crée une transaction cotisation + sa ligne sans déclencher l'observer,
 * puis supprime toutes les adhésions créées (simulation historique pré-backfill).
 */
function createCotisationTxSansAdhesion(array $txAttrs = []): Transaction
{
    $sc = SousCategorie::factory()->pourCotisations()->create();

    /** @var Transaction $tx */
    $tx = null;

    Transaction::withoutEvents(function () use ($txAttrs, $sc, &$tx): void {
        TransactionLigne::withoutEvents(function () use ($txAttrs, $sc, &$tx): void {
            $tx = Transaction::factory()->asRecette()->create($txAttrs);
            // Remove auto-created random lignes
            TransactionLigne::where('transaction_id', $tx->id)->forceDelete();
            // Add the cotisation ligne
            TransactionLigne::factory()->create([
                'transaction_id' => $tx->id,
                'sous_categorie_id' => $sc->id,
            ]);
        });
    });

    return $tx;
}

it('backfill génère les adhésions manquantes pour les transactions cotisations existantes', function (): void {
    $tiers = Tiers::factory()->create();
    createCotisationTxSansAdhesion(['tiers_id' => $tiers->id, 'date' => '2025-10-15']);

    // Simulate historical scenario: adhesions may have been created by observer, force-delete all
    Adhesion::withoutEvents(fn () => Adhesion::query()->forceDelete());
    expect(Adhesion::withTrashed()->count())->toBe(0);

    $this->artisan('adhesions:backfill')->assertSuccessful();

    expect(Adhesion::count())->toBe(1);
    $adhesion = Adhesion::first();
    expect($adhesion->estGratuite())->toBeFalse();
    expect($adhesion->tiers_id)->toBe($tiers->id);
    expect($adhesion->exercice)->toBe(2025);
});

it('backfill est idempotent', function (): void {
    $tiers = Tiers::factory()->create();
    createCotisationTxSansAdhesion(['tiers_id' => $tiers->id, 'date' => '2025-10-15']);

    Adhesion::withoutEvents(fn () => Adhesion::query()->forceDelete());
    expect(Adhesion::withTrashed()->count())->toBe(0);

    $this->artisan('adhesions:backfill')->assertSuccessful();
    expect(Adhesion::count())->toBe(1);

    // Re-run: must not create duplicates
    $this->artisan('adhesions:backfill')->assertSuccessful();
    expect(Adhesion::count())->toBe(1);
});

it('backfill multi-tenant : ne fuit pas entre associations', function (): void {
    // Association 1 (booted by default in Pest.php beforeEach)
    $tiers1 = Tiers::factory()->create();
    createCotisationTxSansAdhesion(['tiers_id' => $tiers1->id, 'date' => '2025-10-15']);
    $asso1 = TenantContext::current();

    // Clear and boot association 2
    TenantContext::clear();
    $asso2 = Association::factory()->create();
    TenantContext::boot($asso2);

    $tiers2 = Tiers::factory()->create();
    createCotisationTxSansAdhesion(['tiers_id' => $tiers2->id, 'date' => '2025-11-01']);

    // Force-delete all adhesions (both associations, bypass tenant scope)
    DB::table('adhesions')->delete();
    expect(Adhesion::withTrashed()->count())->toBe(0);

    // Run backfill (loops all associations internally)
    $this->artisan('adhesions:backfill')->assertSuccessful();

    // From asso2 perspective: should see only asso2's adhesion
    expect(Adhesion::count())->toBe(1);
    expect(Adhesion::first()->tiers_id)->toBe($tiers2->id);

    // Switch to asso1 and verify it sees only its own adhesion
    TenantContext::clear();
    TenantContext::boot($asso1);
    expect(Adhesion::count())->toBe(1);
    expect(Adhesion::first()->tiers_id)->toBe($tiers1->id);
});
