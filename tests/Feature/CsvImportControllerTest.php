<?php

declare(strict_types=1);

use App\Models\User;

it('télécharge le template dépense', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('transactions.import.template', ['type' => 'depense']));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/csv;charset=UTF-8');
    $response->assertDownload('modele-depense.csv');

    // Check header row and example data are present
    expect($response->getContent())
        ->toContain('date;reference;sous_categorie')
        ->toContain('FAC-001');
});

it('télécharge le template recette', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('transactions.import.template', ['type' => 'recette']));

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/csv;charset=UTF-8');
    $response->assertDownload('modele-recette.csv');

    expect($response->getContent())
        ->toContain('date;reference;sous_categorie')
        ->toContain('SUB-001');
});

it('redirige les invités vers login', function () {
    $response = $this->get(route('transactions.import.template', ['type' => 'depense']));
    $response->assertRedirect(route('login'));
});
