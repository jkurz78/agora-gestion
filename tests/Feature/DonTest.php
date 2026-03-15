<?php

use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\User;
use App\Services\DonService;

it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte = CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->pourRecettes()->create();

    $don = app(DonService::class)->create([
        'date' => '2025-10-01',
        'montant' => 50,
        'mode_paiement' => 'especes',
        'compte_id' => $compte->id,
        'tiers_id' => $tiers->id,
    ]);

    expect($don->numero_piece)->not->toBeNull();
    expect($don->numero_piece)->toStartWith('2025-2026:');
});
