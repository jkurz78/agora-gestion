<?php

use App\Livewire\RapportCompteResultat;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders with exercice', function () {
    Livewire::test(RapportCompteResultat::class)
        ->assertOk()
        ->assertSee('Charges')
        ->assertSee('Produits')
        ->assertSee('Exporter CSV');
});

it('shows charges and produits', function () {
    $depenseCat = Categorie::factory()->depense()->create();
    $sc1 = SousCategorie::factory()->create([
        'categorie_id' => $depenseCat->id,
        'nom' => 'Fournitures bureau',
        'code_cerfa' => '60',
    ]);

    $recetteCat = Categorie::factory()->recette()->create();
    $sc2 = SousCategorie::factory()->create([
        'categorie_id' => $recetteCat->id,
        'nom' => 'Cotisations membres',
        'code_cerfa' => '75',
    ]);

    $depense = Depense::factory()->create([
        'date' => '2025-11-15',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc1->id,
        'montant' => 250.00,
    ]);

    $recette = Recette::factory()->create([
        'date' => '2025-12-01',
        'saisi_par' => $this->user->id,
    ]);
    $recette->lignes()->forceDelete();
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $sc2->id,
        'montant' => 800.00,
    ]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('Fournitures bureau')
        ->assertSee('250,00')
        ->assertSee('Cotisations membres')
        ->assertSee('800,00');
});
