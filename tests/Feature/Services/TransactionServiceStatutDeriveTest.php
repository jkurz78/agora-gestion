<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->service = app(TransactionService::class);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('une recette réglée ne peut pas être repassée directement en attente', function (): void {
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Recette réglée',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $transaction = $this->service->create($data, [[
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    expect(fn () => $this->service->update($transaction, [...$data, 'mode_paiement' => null], [[
        'id' => null,
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]))->toThrow(RuntimeException::class, 'mode de paiement');

    expect($transaction->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);
});

it('une dépense réglée ne peut pas être repassée directement en attente', function (): void {
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-15',
        'libelle' => 'Dépense réglée',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ];
    $transaction = $this->service->create($data, [[
        'compte_id' => $this->compte606->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);

    expect(fn () => $this->service->update($transaction, [...$data, 'mode_paiement' => null], [[
        'id' => null,
        'compte_id' => $this->compte606->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]))->toThrow(RuntimeException::class, 'mode de paiement');

    expect($transaction->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});
