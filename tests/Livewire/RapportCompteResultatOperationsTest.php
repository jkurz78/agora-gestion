<?php

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Operation;
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

it('se rend avec la liste des opérations', function () {
    $op = Operation::factory()->create(['nom' => 'Festival été']);
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertOk()
        ->assertSee('Festival été');
});

it('affiche un message si aucune opération sélectionnée', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSee('Sélectionnez au moins une opération');
});

it('affiche les données filtrées par opération', function () {
    $op  = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $cat = Categorie::factory()->depense()->create(['nom' => 'Frais']);
    $sc  = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Transport']);

    $d = Depense::factory()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);
    DepenseLigne::factory()->create(['depense_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op2->id, 'montant' => 999.00]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->assertSee('Transport')
        ->assertSee('100,00')
        ->assertDontSee('999,00');
});

it('désactive le bouton CSV quand aucune opération sélectionnée', function () {
    // Blade rend {{ $hasSelection ? '' : 'disabled' }} → attribut booléen "disabled" sur le bouton
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSee('Exporter CSV')
        ->assertSeeHtml(' disabled>');
});
