<?php

declare(strict_types=1);

use App\Livewire\TransactionUniverselle;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('filters transactions by sous-categorie pour_dons flag', function () {
    $compte = CompteBancaire::factory()->create();
    $scDon = SousCategorie::factory()->pourDons()->create();
    $scAutre = SousCategorie::factory()->create();

    $today = now()->toDateString();

    $txDon = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => $today,
        'libelle' => 'Don transaction visible',
    ]);
    // Remove auto-created lines, then add specific ones
    TransactionLigne::where('transaction_id', $txDon->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txDon->id,
        'sous_categorie_id' => $scDon->id,
        'montant' => 100,
    ]);

    $txAutre = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => $today,
        'libelle' => 'Autre transaction cachée',
    ]);
    TransactionLigne::where('transaction_id', $txAutre->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txAutre->id,
        'sous_categorie_id' => $scAutre->id,
        'montant' => 50,
    ]);

    Livewire::test(TransactionUniverselle::class, [
        'sousCategorieFilter' => 'pour_dons',
    ])
        ->assertSee('Don transaction visible')
        ->assertDontSee('Autre transaction cachée');
});

it('filters transactions by sous-categorie pour_cotisations flag', function () {
    $compte = CompteBancaire::factory()->create();
    $scCot = SousCategorie::factory()->pourCotisations()->create();
    $scAutre = SousCategorie::factory()->create();

    $today = now()->toDateString();

    $txCot = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => $today,
        'libelle' => 'Cotisation transaction visible',
    ]);
    TransactionLigne::where('transaction_id', $txCot->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txCot->id,
        'sous_categorie_id' => $scCot->id,
        'montant' => 80,
    ]);

    $txAutre = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => $today,
        'libelle' => 'Autre cotisation cachée',
    ]);
    TransactionLigne::where('transaction_id', $txAutre->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $txAutre->id,
        'sous_categorie_id' => $scAutre->id,
        'montant' => 30,
    ]);

    Livewire::test(TransactionUniverselle::class, [
        'sousCategorieFilter' => 'pour_cotisations',
    ])
        ->assertSee('Cotisation transaction visible')
        ->assertDontSee('Autre cotisation cachée');
});
