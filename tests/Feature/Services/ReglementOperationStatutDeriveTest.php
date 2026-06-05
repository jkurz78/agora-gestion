<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('marquerRecu chèque sur créance → statut dérivé EnMain', function () {
    $creance = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Créance à encaisser',
        'montant_total' => '100.00',
        'mode_paiement' => null,
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);
    expect($creance->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);

    app(ReglementOperationService::class)->marquerRecu(
        $creance->fresh(), ModePaiement::Cheque, (int) $this->compteBancaire->id
    );

    expect($creance->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

it('marquerRecu virement sur créance → statut dérivé Recu', function () {
    $creance = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Créance virement',
        'montant_total' => '100.00',
        'mode_paiement' => null,
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    app(ReglementOperationService::class)->marquerRecu(
        $creance->fresh(), ModePaiement::Virement, (int) $this->compteBancaire->id
    );

    expect($creance->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('marquerPaye virement sur dette → statut dérivé Recu (réglé)', function () {
    $dette = app(TransactionService::class)->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-15',
        'libelle' => 'Dette à régler',
        'montant_total' => '50.00',
        'mode_paiement' => null,
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ], [[
        'sous_categorie_id' => $this->sc606->id,
        'montant' => '50.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    app(ReglementOperationService::class)->marquerPaye(
        $dette->fresh(), ModePaiement::Virement, (int) $this->compteBancaire->id
    );

    expect($dette->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});
