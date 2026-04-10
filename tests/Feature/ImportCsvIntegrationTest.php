<?php

declare(strict_types=1);

use App\Models\User;

it('la page transactions contient le composant import-csv', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('compta.transactions.index'));

    $response->assertStatus(200);
    $response->assertSee('Importer'); // bouton toggle du composant import-csv
});
