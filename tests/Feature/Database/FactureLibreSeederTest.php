<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Models\Compte;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\User;
use Database\Seeders\FactureManuelSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Smoke test — FactureLibreSeeder.
 *
 * Vérifie que le seeder s'exécute sans exception et produit les 3 cas démo attendus.
 * Le TenantContext est booté par le beforeEach global de Pest.php.
 */
it('runs without error via Artisan and seeds demo factures manuelles', function () {
    // Pré-requis : un tiers et un compte de produit tenant-scopés.
    Tiers::factory()->create();
    Compte::factory()->numero('706A')->create(['intitule' => 'Formations']);
    User::factory()->create();

    $exitCode = Artisan::call('db:seed', ['--class' => 'FactureManuelSeeder']);

    expect($exitCode)->toBe(0);
});

it('seeds at least one facture brouillon issue d un devis', function () {
    Tiers::factory()->create();
    Compte::factory()->numero('706A')->create(['intitule' => 'Formations']);
    User::factory()->create();

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

    (new FactureManuelSeeder)->run();

    expect(FactureLigne::whereNotNull('compte_id')->pluck('compte_id')->unique()->all())
        ->toBe([$compteProduit->id]);
});

it('is idempotent on second run', function () {
    Tiers::factory()->create();
    Compte::factory()->numero('706A')->create(['intitule' => 'Formations']);
    User::factory()->create();

    $seeder = new FactureManuelSeeder;
    $seeder->run();

    $countAfterFirst = Facture::whereNotNull('devis_id')->count();

    // Second run must be a no-op
    $seeder->run();

    expect(Facture::whereNotNull('devis_id')->count())->toBe($countAfterFirst);
});
