<?php

declare(strict_types=1);

use App\Livewire\TransactionList;
use App\Models\User;
use Livewire\Livewire;

it('TransactionList se rafraîchit sur csv-imported', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(TransactionList::class)
        ->dispatch('csv-imported')
        ->assertOk(); // component re-renders without error
});

it('la page transactions contient le composant import-csv', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('transactions.index'));

    $response->assertStatus(200);
    $response->assertSee('Importer'); // bouton toggle du composant import-csv
});
