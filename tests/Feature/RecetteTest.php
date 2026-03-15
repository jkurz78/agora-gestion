<?php

use App\Models\User;

it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte = \App\Models\CompteBancaire::factory()->create();

    $recette = app(\App\Services\RecetteService::class)->create([
        'date'          => '2025-10-01',
        'libelle'       => 'Test',
        'montant_total' => 100,
        'mode_paiement' => 'virement',
        'compte_id'     => $compte->id,
        'reference'     => 'REC-001',
    ], []);

    expect($recette->numero_piece)->not->toBeNull();
    expect($recette->numero_piece)->toStartWith('2025-2026:');
});
