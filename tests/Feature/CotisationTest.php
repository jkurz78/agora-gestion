<?php

use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Services\CotisationService;

it('create assigne un numero_piece non null', function () {
    $compte = CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->membre()->create();
    $sousCategorie = SousCategorie::factory()->pourCotisations()->create();

    $cotisation = app(CotisationService::class)->create($tiers, [
        'date_paiement' => '2025-10-01',
        'exercice' => 2025,
        'montant' => 80,
        'mode_paiement' => 'virement',
        'compte_id' => $compte->id,
        'sous_categorie_id' => $sousCategorie->id,
    ]);

    expect($cotisation->numero_piece)->not->toBeNull();
    expect($cotisation->numero_piece)->toStartWith('2025-2026:');
});
