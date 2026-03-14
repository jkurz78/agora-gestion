<?php

use App\Livewire\Dashboard;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Membre;
use App\Models\Recette;
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
        ->assertSee('Membres sans cotisation')
        ->assertSee('Comptes bancaires')
        ->assertSee('Aucun compte bancaire configuré');
});


it('displays solde general', function () {
    Recette::factory()->create([
        'date' => $this->exercice.'-11-01',
        'montant_total' => 1000.00,
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'date' => $this->exercice.'-12-01',
        'montant_total' => 500.00,
        'saisi_par' => $this->user->id,
    ]);

    Depense::factory()->create([
        'date' => $this->exercice.'-10-15',
        'montant_total' => 300.00,
        'saisi_par' => $this->user->id,
    ]);

    // Solde = 1000 + 500 - 300 = 1200
    Livewire::test(Dashboard::class)
        ->assertSee('1 200,00');
});

it('shows membres without cotisation', function () {
    $membreWithCotisation = Membre::factory()->create([
        'nom' => 'Durand',
        'prenom' => 'Marie',
    ]);
    Cotisation::factory()->create([
        'membre_id' => $membreWithCotisation->id,
        'exercice' => $this->exercice,
    ]);

    $membreSansCotisation = Membre::factory()->create([
        'nom' => 'Martin',
        'prenom' => 'Pierre',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Martin')
        ->assertSee('Pierre')
        ->assertDontSee('Durand');
});

it('displays comptes bancaires with soldes', function () {
    CompteBancaire::factory()->create([
        'nom'                => 'Compte Principal',
        'solde_initial'      => 1500.00,
        'date_solde_initial' => '2024-01-01',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Comptes bancaires')
        ->assertSee('Compte Principal')
        ->assertSee('1 500,00');
});
