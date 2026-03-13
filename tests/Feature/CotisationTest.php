<?php

it('create assigne un numero_piece non null', function () {
    $compte = \App\Models\CompteBancaire::factory()->create();
    $membre = \App\Models\Membre::factory()->create();

    $cotisation = app(\App\Services\CotisationService::class)->create($membre, [
        'date_paiement' => '2025-10-01',
        'exercice'      => 2025,
        'montant'       => 80,
        'mode_paiement' => 'virement',
        'compte_id'     => $compte->id,
    ]);

    expect($cotisation->numero_piece)->not->toBeNull();
    expect($cotisation->numero_piece)->toStartWith('2025-2026:');
});
