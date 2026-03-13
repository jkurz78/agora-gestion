<?php

use App\Models\User;

it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte = \App\Models\CompteBancaire::factory()->create();
    $donateur = \App\Models\Donateur::factory()->create();

    $don = app(\App\Services\DonService::class)->create([
        'date'          => '2025-10-01',
        'montant'       => 50,
        'mode_paiement' => 'especes',
        'compte_id'     => $compte->id,
        'donateur_id'   => $donateur->id,
    ]);

    expect($don->numero_piece)->not->toBeNull();
    expect($don->numero_piece)->toStartWith('2025-2026:');
});
