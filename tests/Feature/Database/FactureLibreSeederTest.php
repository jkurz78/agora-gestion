<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Models\Facture;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use Database\Seeders\FactureLibreSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Smoke test — FactureLibreSeeder.
 *
 * Vérifie que le seeder s'exécute sans exception et produit les 3 cas démo attendus.
 * Le TenantContext est booté par le beforeEach global de Pest.php.
 */
it('runs without error via Artisan and seeds demo factures libres', function () {
    // Pré-requis : un Tiers et une SousCategorie tenant-scopés
    Tiers::factory()->create();
    SousCategorie::factory()->create(['nom' => 'Formations']);
    User::factory()->create();

    $exitCode = Artisan::call('db:seed', ['--class' => 'FactureLibreSeeder']);

    expect($exitCode)->toBe(0);
});

it('seeds at least one facture brouillon issue d un devis', function () {
    Tiers::factory()->create();
    SousCategorie::factory()->create(['nom' => 'Formations']);
    User::factory()->create();

    (new FactureLibreSeeder)->run();

    expect(
        Facture::whereNotNull('devis_id')
            ->where('statut', StatutFacture::Brouillon->value)
            ->count()
    )->toBeGreaterThanOrEqual(1);
});

it('seeds a validated facture libre with a linked transaction', function () {
    Tiers::factory()->create();
    SousCategorie::factory()->create(['nom' => 'Formations']);
    User::factory()->create();

    (new FactureLibreSeeder)->run();

    $factureValidee = Facture::where('statut', StatutFacture::Validee->value)
        ->whereNull('devis_id')
        ->first();

    expect($factureValidee)->not->toBeNull();
    expect($factureValidee->transactions()->count())->toBeGreaterThanOrEqual(1);
});

it('is idempotent on second run', function () {
    Tiers::factory()->create();
    SousCategorie::factory()->create(['nom' => 'Formations']);
    User::factory()->create();

    $seeder = new FactureLibreSeeder;
    $seeder->run();

    $countAfterFirst = Facture::whereNotNull('devis_id')->count();

    // Second run must be a no-op
    $seeder->run();

    expect(Facture::whereNotNull('devis_id')->count())->toBe($countAfterFirst);
});
