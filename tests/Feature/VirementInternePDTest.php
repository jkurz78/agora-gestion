<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\VirementInterneService;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function creerCompteBancairesPD(): array
{
    $cb1 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
    $cb2 = CompteBancaire::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);

    BancairesSeeder::seed();

    return [$cb1, $cb2];
}

// ---------------------------------------------------------------------------
// beforeEach
// ---------------------------------------------------------------------------

beforeEach(function () {
    SystemeSeeder::seed();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// ---------------------------------------------------------------------------
// CREATE
// ---------------------------------------------------------------------------

it('create() generates a PD transaction when use_partie_double is true', function () {
    config(['compta.use_partie_double' => true]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
        'reference' => 'VIR-001',
    ]);

    $transaction = Transaction::where('virement_interne_id', $virement->id)->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->type)->toBe(TypeTransaction::Virement);
    expect($transaction->journal)->toBe(JournalComptable::Banque);
    expect((float) $transaction->montant_total)->toBe(750.00);
    expect($transaction->numero_piece)->toBe($virement->numero_piece);
    expect($transaction->lignes)->toHaveCount(2);
});

it('create() does NOT generate a PD transaction when use_partie_double is false', function () {
    config(['compta.use_partie_double' => false]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    expect(Transaction::where('virement_interne_id', $virement->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// UPDATE
// ---------------------------------------------------------------------------

it('update() recreates the PD transaction with new values', function () {
    config(['compta.use_partie_double' => true]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    $oldTransactionId = Transaction::where('virement_interne_id', $virement->id)->first()->id;

    $virement = $service->update($virement, [
        'date' => '2026-01-16',
        'montant' => 1200.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    // Old transaction hard-deleted
    expect(Transaction::withTrashed()->find($oldTransactionId))->toBeNull();

    // New transaction exists with updated values
    $newTx = Transaction::where('virement_interne_id', $virement->id)->first();
    expect($newTx)->not->toBeNull();
    expect((float) $newTx->montant_total)->toBe(1200.00);
    expect($newTx->date->format('Y-m-d'))->toBe('2026-01-16');
    expect($newTx->lignes)->toHaveCount(2);
});

it('update() works when PD is off (no transaction to delete)', function () {
    config(['compta.use_partie_double' => false]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    $virement = $service->update($virement, [
        'date' => '2026-01-16',
        'montant' => 1200.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    expect((float) $virement->montant)->toBe(1200.00);
    expect(Transaction::where('virement_interne_id', $virement->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// DELETE
// ---------------------------------------------------------------------------

it('delete() removes the PD transaction along with the virement', function () {
    config(['compta.use_partie_double' => true]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    $transactionId = Transaction::where('virement_interne_id', $virement->id)->first()->id;

    $service->delete($virement);

    // Virement soft-deleted
    expect(VirementInterne::find($virement->id))->toBeNull();

    // Transaction hard-deleted
    expect(Transaction::withTrashed()->find($transactionId))->toBeNull();

    // Lignes cascade-deleted
    expect(TransactionLigne::where('transaction_id', $transactionId)->exists())->toBeFalse();
});

it('delete() works when PD is off (no transaction to delete)', function () {
    config(['compta.use_partie_double' => false]);
    [$cb1, $cb2] = creerCompteBancairesPD();

    $service = app(VirementInterneService::class);
    $virement = $service->create([
        'date' => '2026-01-15',
        'montant' => 750.00,
        'compte_source_id' => $cb1->id,
        'compte_destination_id' => $cb2->id,
    ]);

    $service->delete($virement);

    expect(VirementInterne::find($virement->id))->toBeNull();
});
