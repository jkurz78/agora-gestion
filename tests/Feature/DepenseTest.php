<?php

use App\Models\User;

it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte = \App\Models\CompteBancaire::factory()->create();

    $depense = app(\App\Services\DepenseService::class)->create([
        'date'          => '2025-10-01',
        'libelle'       => 'Test',
        'montant_total' => 100,
        'mode_paiement' => 'virement',
        'compte_id'     => $compte->id,
        'reference'     => 'FAC-001',
    ], []);

    expect($depense->numero_piece)->not->toBeNull();
    expect($depense->numero_piece)->toStartWith('2025-2026:');
});
