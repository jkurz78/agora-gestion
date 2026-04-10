<?php

use App\Livewire\Dashboard;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
    $this->exercice = 2025;
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders for authenticated user', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Solde général')
        ->assertSee('Résumé budget')
        ->assertSee('Dernières dépenses')
        ->assertSee('Dernières recettes')
        ->assertSee('Derniers dons')
        ->assertSee('Dernières adhésions')
        ->assertSee('Opérations')
        ->assertSee('Comptes bancaires')
        ->assertSee('Aucun compte bancaire configuré');
});

it('displays solde general', function () {
    Transaction::factory()->asRecette()->create([
        'date' => $this->exercice.'-11-01',
        'montant_total' => 1000.00,
        'saisi_par' => $this->user->id,
    ]);
    Transaction::factory()->asRecette()->create([
        'date' => $this->exercice.'-12-01',
        'montant_total' => 500.00,
        'saisi_par' => $this->user->id,
    ]);

    Transaction::factory()->asDepense()->create([
        'date' => $this->exercice.'-10-15',
        'montant_total' => 300.00,
        'saisi_par' => $this->user->id,
    ]);

    // Solde = 1000 + 500 - 300 = 1200
    Livewire::test(Dashboard::class)
        ->assertSee('1 200,00');
});

it('shows dernieres adhesions', function () {
    $cotSc = SousCategorie::factory()->create(['pour_cotisations' => true]);

    $tx = Transaction::factory()->asRecette()->create([
        'date' => $this->exercice.'-10-01',
        'montant_total' => 30.00,
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $cotSc->id,
        'montant' => 30.00,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('30,00');
});

it('displays comptes bancaires with soldes', function () {
    CompteBancaire::factory()->create([
        'nom' => 'Compte Principal',
        'solde_initial' => 1500.00,
        'date_solde_initial' => '2024-01-01',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Compte Principal')
        ->assertSee('1 500,00');
});
