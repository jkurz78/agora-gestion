<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Exceptions\Compta\PartieDoubleIncompleteException;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\PartieDoubleGuard;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();
});

it('create() avec PD active et compte valide → guard passe, equilibree=true', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $service = app(TransactionService::class);

    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-01',
        'libelle' => 'Recette mappée PD',
        'montant_total' => '200.00',
        'mode_paiement' => 'virement',
        'compte_id' => $this->compteBancaire->id,
        'tiers_id' => $tiers->id,
    ];
    $lignes = [['compte_id' => $this->compte706->id, 'montant' => '200.00']];

    $transaction = $service->create($data, $lignes);

    expect((bool) $transaction->equilibree)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 3 : guard détecte une transaction marquée equilibree=true mais avec
//          des lignes PD déséquilibrées (test direct du guard via helper)
// ---------------------------------------------------------------------------

it('PartieDoubleGuard::assertComplete lance une exception si debit ≠ credit', function () {
    // Simuler une transaction avec equilibree=true mais des lignes déséquilibrées
    $compte = $this->compte706;

    $transaction = Transaction::create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'date' => '2025-10-01',
        'libelle' => 'TX déséquilibrée',
        'montant_total' => 100.0,
        'saisi_par' => $this->user->id,
        'equilibree' => true,   // marquée équilibrée mais lignes tronquées
    ]);

    // Insérer une seule ligne PD (débit mais pas de crédit correspondant)
    // Utiliser DB::table pour contourner l'observer XOR qui empêche debit>0 ET credit=0 seul.
    DB::table('transaction_lignes')->insert([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte->id,
        'debit' => 100.0,
        'credit' => 0.0,
        'montant' => 0.0,
    ]);

    expect(fn () => PartieDoubleGuard::assertComplete($transaction->fresh()))
        ->toThrow(PartieDoubleIncompleteException::class);
});
