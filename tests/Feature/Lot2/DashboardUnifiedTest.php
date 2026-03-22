<?php

declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows recent donations from transaction_lignes with pour_dons sous-categorie', function () {
    $compte = CompteBancaire::factory()->create();
    $scDon = SousCategorie::factory()->pourDons()->create();
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);

    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'tiers_id' => $tiers->id,
        'date' => now()->subDays(5),
        'montant_total' => 100.00,
        'libelle' => 'Don test',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scDon->id,
        'montant' => 100.00,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Don test');
});

it('shows tiers without cotisation for current exercice', function () {
    $compte = CompteBancaire::factory()->create();
    $scCot = SousCategorie::factory()->pourCotisations()->create();
    $exercice = app(ExerciceService::class)->current();

    $tiersAvec = Tiers::factory()->create(['nom' => 'Avec', 'prenom' => 'Cot']);
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'tiers_id' => $tiersAvec->id,
        'date' => now(),
        'montant_total' => 50.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scCot->id,
        'montant' => 50.00,
        'exercice' => $exercice,
    ]);

    // Tiers who has had cotisations before but not this exercice
    $tiersSans = Tiers::factory()->create(['nom' => 'Sans', 'prenom' => 'Cot']);
    $txOld = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'tiers_id' => $tiersSans->id,
        'date' => now()->subYear(),
        'montant_total' => 50.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $txOld->id,
        'sous_categorie_id' => $scCot->id,
        'montant' => 50.00,
        'exercice' => $exercice - 1,
    ]);

    Livewire::test(Dashboard::class)
        ->assertDontSee('Avec Cot')
        ->assertSee('Sans');
});
