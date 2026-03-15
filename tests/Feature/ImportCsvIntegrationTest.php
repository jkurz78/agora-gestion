<?php

declare(strict_types=1);

use App\Livewire\DepenseList;
use App\Livewire\RecetteList;
use App\Models\User;
use Livewire\Livewire;

it('DepenseList se rafraîchit sur csv-imported', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(DepenseList::class)
        ->dispatch('csv-imported')
        ->assertOk(); // component re-renders without error
});

it('RecetteList se rafraîchit sur csv-imported', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(RecetteList::class)
        ->dispatch('csv-imported')
        ->assertOk();
});

it('la page dépenses contient le composant import-csv', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('depenses.index'));

    $response->assertStatus(200);
    $response->assertSee('Importer'); // button text from import-csv component
    $response->assertSee('Télécharger le modèle');
});

it('la page recettes contient le composant import-csv', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('recettes.index'));

    $response->assertStatus(200);
    $response->assertSee('Importer');
    $response->assertSee('Télécharger le modèle');
});
