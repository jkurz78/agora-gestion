<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Compta\ComptesProvisioningService;
use App\Tenant\TenantContext;
use Database\Seeders\FactureManuelSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Smoke test — FactureLibreSeeder.
 *
 * Vérifie que le seeder s'exécute sans exception et produit les 3 cas démo attendus.
 * Le TenantContext est booté par le beforeEach global de Pest.php.
 */
function provisionFactureManuelLedger(): void
{
    CompteBancaire::factory()->create();
    app(ComptesProvisioningService::class)->provisionAll();
}

it('runs without error via Artisan and seeds demo factures manuelles', function () {
    // Pré-requis : un tiers et un compte de produit tenant-scopés.
    Tiers::factory()->create();
    Compte::factory()->numero('706A')->create(['intitule' => 'Formations']);
    User::factory()->create();
    provisionFactureManuelLedger();

    $exitCode = Artisan::call('db:seed', ['--class' => 'FactureManuelSeeder']);

    expect($exitCode)->toBe(0);
});

it('seeds at least one facture brouillon issue d un devis', function () {
    Tiers::factory()->create();
    Compte::factory()->numero('706A')->create(['intitule' => 'Formations']);
    User::factory()->create();
    provisionFactureManuelLedger();

    (new FactureManuelSeeder)->run();

    expect(
        Facture::whereNotNull('devis_id')
            ->where('statut', StatutFacture::Brouillon->value)
            ->count()
    )->toBeGreaterThanOrEqual(1);
});

it('seeds a validated facture manuelle with a linked transaction', function () {
    Tiers::factory()->create();
    Compte::factory()->numero('706A')->create(['intitule' => 'Formations']);
    User::factory()->create();
    provisionFactureManuelLedger();

    (new FactureManuelSeeder)->run();

    $factureValidee = Facture::where('statut', StatutFacture::Validee->value)
        ->whereNull('devis_id')
        ->first();

    expect($factureValidee)->not->toBeNull();
    expect($factureValidee->transactions()->count())->toBeGreaterThanOrEqual(1);
});

it('uses a product account when the preferred training account is missing', function () {
    Tiers::factory()->create();
    Compte::factory()->numero('606A')->create(['intitule' => 'Achats divers']);
    $compteProduit = Compte::factory()->numero('707A')->create(['intitule' => 'Ventes diverses']);
    User::factory()->create();
    provisionFactureManuelLedger();

    (new FactureManuelSeeder)->run();

    expect(FactureLigne::whereNotNull('compte_id')->pluck('compte_id')->unique()->all())
        ->toBe([$compteProduit->id]);
});

it('seeds only balanced T1 entries and passes the V5 smoke test', function () {
    Tiers::factory()->create();
    Compte::factory()->numero('706A')->create(['intitule' => 'Formations']);
    User::factory()->create();
    provisionFactureManuelLedger();

    (new FactureManuelSeeder)->run();

    $transactions = Transaction::query()->with('lignes.compte')->get();
    expect($transactions)->toHaveCount(2);

    foreach ($transactions as $transaction) {
        expect(round((float) $transaction->lignes->sum('debit'), 2))
            ->toBe(round((float) $transaction->lignes->sum('credit'), 2));
        expect($transaction->lignes->contains(
            fn ($ligne): bool => $ligne->compte?->numero_pcg === '411' && (float) $ligne->debit > 0
        ))->toBeTrue();
        expect($transaction->lignes->contains(
            fn ($ligne): bool => $ligne->compte?->classe === 7 && (float) $ligne->credit > 0
        ))->toBeTrue();
    }

    expect(Artisan::call('compta:smoke-test-v5', [
        '--asso' => [(int) TenantContext::currentId()],
    ]))->toBe(0, Artisan::output());

    $imbalanced = DB::table('transaction_lignes')
        ->select('transaction_id')
        ->groupBy('transaction_id')
        ->havingRaw('ROUND(SUM(debit), 2) <> ROUND(SUM(credit), 2)')
        ->count();
    expect($imbalanced)->toBe(0);
});

it('is idempotent on second run', function () {
    Tiers::factory()->create();
    Compte::factory()->numero('706A')->create(['intitule' => 'Formations']);
    User::factory()->create();
    provisionFactureManuelLedger();

    $seeder = new FactureManuelSeeder;
    $seeder->run();

    $countAfterFirst = Facture::whereNotNull('devis_id')->count();

    // Second run must be a no-op
    $seeder->run();

    expect(Facture::whereNotNull('devis_id')->count())->toBe($countAfterFirst);
});
