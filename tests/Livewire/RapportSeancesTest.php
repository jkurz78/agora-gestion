<?php

use App\Livewire\RapportSeances;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders with operation selector', function () {
    $operation = Operation::factory()->withSeances(3)->create(['nom' => 'Festival été']);

    Livewire::test(RapportSeances::class)
        ->assertOk()
        ->assertSee('Festival été')
        ->assertSee('Opération');
});

it('shows pivot table when operation selected', function () {
    $operation = Operation::factory()->withSeances(2)->create();
    $depenseCat = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create([
        'categorie_id' => $depenseCat->id,
        'nom' => 'Location salle',
    ]);

    $depense = Depense::factory()->create([
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => $operation->id,
        'seance' => 1,
        'montant' => 100.00,
    ]);
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => $operation->id,
        'seance' => 2,
        'montant' => 150.00,
    ]);

    Livewire::test(RapportSeances::class)
        ->set('operation_id', $operation->id)
        ->assertSee('Location salle')
        ->assertSee('Séance 1')
        ->assertSee('Séance 2')
        ->assertSee('100,00')
        ->assertSee('150,00');
});
