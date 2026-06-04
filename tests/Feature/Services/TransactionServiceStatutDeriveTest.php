<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->service = app(TransactionService::class);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('réversion recette reçue→non-reçue : le statut dérivé repasse EnAttente (bug recette 2a)', function () {
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette réversible',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $lignes = [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]];

    $t1 = $this->service->create($data, $lignes);
    expect($t1->fresh()->statut_reglement)->not->toBe(StatutReglement::EnAttente);

    // Réversion : repasser en mode null (non reçue).
    $this->service->update($t1, [...$data, 'mode_paiement' => null, 'compte_id' => null], [[
        'id' => null,
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});

it('réversion dépense réglée→non-payée : le statut dérivé repasse EnAttente (symétrie 401)', function () {
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-15',
        'libelle' => 'Dépense réversible',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];

    $t1 = $this->service->create($data, [[
        'sous_categorie_id' => $this->sc606->id,
        'montant' => '50.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);
    expect($t1->fresh()->statut_reglement)->not->toBe(StatutReglement::EnAttente);

    $this->service->update($t1, [...$data, 'mode_paiement' => null, 'compte_id' => null], [[
        'id' => null,
        'sous_categorie_id' => $this->sc606->id,
        'montant' => '50.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
});
