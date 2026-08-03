<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\Tiers;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('toutes les transactions complètes → --check exit 0', function () {
    app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Transaction PD complète',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $this->artisan('compta:assert-pd-complete', ['--check' => true])
        ->assertExitCode(0);
});

it('transaction incomplète → --check exit 1', function () {
    $tx = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Transaction corrompue',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    // Corrompre le flag equilibree
    $tx->forceFill(['equilibree' => false])->save();

    $this->artisan('compta:assert-pd-complete', ['--check' => true])
        ->assertExitCode(1);
});

it('les transactions HelloAsso sont exemptées → --check exit 0', function () {
    $tx = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Transaction HelloAsso',
        'montant_total' => '50.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'compte_id' => $this->compte706->id,
        'montant' => '50.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    // Marquer comme HelloAsso et corrompre — la commande doit ignorer
    $tx->forceFill(['helloasso_order_id' => 99999, 'equilibree' => false])->save();

    $this->artisan('compta:assert-pd-complete', ['--check' => true])
        ->assertExitCode(0);
});
