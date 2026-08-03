<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->service = app(TransactionService::class);
    $this->reglements = app(ReglementOperationService::class);
});

it('empêche une modification directe qui créerait une T2 zombie', function (): void {
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
    $t2 = $this->reglements->trouverEncaissementT2($transaction);
    expect($t2)->not->toBeNull();

    expect(fn () => $this->service->update($transaction, [...$data, 'montant_total' => '150.00'], [[
        'id' => null,
        'compte_id' => $this->compte706->id,
        'montant' => '150.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]))->toThrow(RuntimeException::class, 'montant total');

    expect(Transaction::count())->toBe(2)
        ->and($this->reglements->trouverEncaissementT2($transaction->fresh())->id)->toBe($t2->id);
});
