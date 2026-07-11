<?php

declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows recent donations from transaction_lignes with pour_dons sous-categorie', function () {
    $compte = CompteBancaire::factory()->create();
    $compteDon = Compte::factory()->numero('754')->pourDons()->create();
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
        'compte_id' => $compteDon->id,
        'montant' => 100.00,
        'debit' => 0.0,
        'credit' => 100.00,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Don test');
});

it('shows recent adhesions on dashboard', function () {
    $compte = CompteBancaire::factory()->create();
    $compteCot = Compte::factory()->numero('756')->pourCotisations()->create();

    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => now(),
        'montant_total' => 50.00,
        'libelle' => 'Adhésion test',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compteCot->id,
        'montant' => 50.00,
        'debit' => 0.0,
        'credit' => 50.00,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('50,00');
});
