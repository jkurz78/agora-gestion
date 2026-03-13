<?php

use App\Models\User;

it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte1 = \App\Models\CompteBancaire::factory()->create();
    $compte2 = \App\Models\CompteBancaire::factory()->create();

    $virement = app(\App\Services\VirementInterneService::class)->create([
        'date'                   => '2025-10-01',
        'montant'                => 200,
        'compte_source_id'       => $compte1->id,
        'compte_destination_id'  => $compte2->id,
    ]);

    expect($virement->numero_piece)->not->toBeNull();
    expect($virement->numero_piece)->toStartWith('2025-2026:');
});
