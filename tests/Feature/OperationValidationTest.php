<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('rejette la création sans date_debut', function () {
    $response = $this->post(route('operations.store'), [
        'nom'    => 'Test op',
        'statut' => 'en_cours',
    ]);
    $response->assertSessionHasErrors('date_debut');
});

it('rejette la création sans date_fin', function () {
    $response = $this->post(route('operations.store'), [
        'nom'       => 'Test op',
        'date_debut' => '2025-09-01',
        'statut'    => 'en_cours',
    ]);
    $response->assertSessionHasErrors('date_fin');
});

it('accepte la création avec les deux dates', function () {
    $response = $this->post(route('operations.store'), [
        'nom'        => 'Test op',
        'date_debut' => '2025-09-01',
        'date_fin'   => '2026-03-31',
        'statut'     => 'en_cours',
    ]);
    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
});

it('rejette la modification sans date_debut', function () {
    $op = \App\Models\Operation::factory()->create();
    $response = $this->put(route('operations.update', $op), [
        'nom'    => 'Test op',
        'statut' => 'en_cours',
    ]);
    $response->assertSessionHasErrors('date_debut');
});
