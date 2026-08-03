<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function (): void {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->compte708 = Compte::factory()->numero('708')->create([
        'association_id' => $this->association->id,
    ]);
    $this->service = app(TransactionService::class);
});

/** @return array{0: Transaction, 1: array<string, mixed>, 2: array<string, mixed>} */
function recetteRegleePourUpdate(object $ctx): array
{
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Cotisation initiale',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $ctx->tiers->id,
        'compte_id' => $ctx->compteBancaire->id,
    ];
    $transaction = $ctx->service->create($data, [[
        'compte_id' => $ctx->compte706->id,
        'montant' => '100.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);
    $ventilation = $transaction->lignes()->ventilation()->sole();

    return [$transaction, $data, [
        'id' => (int) $ventilation->id,
        'compte_id' => (int) $ventilation->compte_id,
        'montant' => $ventilation->montant,
        'operation_id' => $ventilation->operation_id,
        'seance' => $ventilation->seance,
        'notes' => $ventilation->notes,
    ]];
}

it('autorise seulement les champs descriptifs sur une T1 réglée', function (): void {
    [$transaction, $data, $ligne] = recetteRegleePourUpdate($this);

    $updated = $this->service->update($transaction, [
        ...$data,
        'libelle' => 'Cotisation initiale — corrigée',
        'reference' => 'REF-CORRIGEE',
        'notes' => 'Note interne',
    ], [$ligne]);

    expect($updated->libelle)->toBe('Cotisation initiale — corrigée')
        ->and($updated->reference)->toBe('REF-CORRIGEE')
        ->and($updated->notes)->toBe('Note interne')
        ->and(Transaction::count())->toBe(2);
});

it('refuse toute modification du grand livre d’une T1 réglée', function (): void {
    [$transaction, $data, $ligne] = recetteRegleePourUpdate($this);

    expect(fn (): Transaction => $this->service->update(
        $transaction,
        [...$data, 'montant_total' => '120.00'],
        [[...$ligne, 'montant' => '120.00']]
    ))->toThrow(RuntimeException::class, 'montant total');

    expect(fn (): Transaction => $this->service->update(
        $transaction,
        [...$data, 'mode_paiement' => ModePaiement::Virement->value],
        [$ligne]
    ))->toThrow(RuntimeException::class, 'mode de paiement');

    expect(fn (): Transaction => $this->service->update(
        $transaction,
        $data,
        [[...$ligne, 'compte_id' => $this->compte708->id]]
    ))->toThrow(RuntimeException::class, 'ventilation');

    expect(Transaction::count())->toBe(2);
});

it('conserve le comportement legacy sans tiers : la partie double est ignorée', function (): void {
    $transaction = $this->service->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-01',
        'libelle' => 'Recette sans tiers',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => null,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]]);
    $ligne = $transaction->lignes()->sole();

    $updated = $this->service->update($transaction, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-01',
        'libelle' => 'Recette sans tiers — modifiée',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => null,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'id' => (int) $ligne->id,
        'compte_id' => (int) $ligne->compte_id,
        'montant' => $ligne->montant,
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => $ligne->notes,
    ]]);

    expect($updated->libelle)->toBe('Recette sans tiers — modifiée');
});
