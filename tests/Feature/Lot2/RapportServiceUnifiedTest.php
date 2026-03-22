<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RapportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes don-type recettes in produits', function () {
    $cat = Categorie::factory()->create(['type' => TypeCategorie::Recette]);
    $scDon = SousCategorie::factory()->pourDons()->create(['categorie_id' => $cat->id, 'nom' => 'Dons manuels']);

    $compte = CompteBancaire::factory()->create();
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => '2025-11-15',
        'montant_total' => 150.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scDon->id,
        'montant' => 150.00,
    ]);

    $result = app(RapportService::class)->compteDeResultat(2025);

    $produits = collect($result['produits']);
    $found = $produits->flatMap(fn ($cat) => $cat['sous_categories'])
        ->firstWhere('label', 'Dons manuels');

    expect($found)->not->toBeNull();
    expect($found['montant_n'])->toBe(150.00);
});

it('includes cotisation-type recettes in produits', function () {
    $cat = Categorie::factory()->create(['type' => TypeCategorie::Recette]);
    $scCot = SousCategorie::factory()->pourCotisations()->create(['categorie_id' => $cat->id, 'nom' => 'Cotisations']);

    $compte = CompteBancaire::factory()->create();
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => '2025-10-01',
        'montant_total' => 80.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scCot->id,
        'montant' => 80.00,
    ]);

    $result = app(RapportService::class)->compteDeResultat(2025);

    $produits = collect($result['produits']);
    $found = $produits->flatMap(fn ($cat) => $cat['sous_categories'])
        ->firstWhere('label', 'Cotisations');

    expect($found)->not->toBeNull();
    expect($found['montant_n'])->toBe(80.00);
});
