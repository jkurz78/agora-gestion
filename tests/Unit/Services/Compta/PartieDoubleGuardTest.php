<?php

declare(strict_types=1);

use App\Exceptions\Compta\PartieDoubleIncompleteException;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\PartieDoubleGuard;
use App\Tenant\TenantContext;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

function makeComptePDG(string $numero = '607'): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numero.'-pdg-'.uniqid(),
        'intitule' => "Compte {$numero}",
        'classe' => (int) substr($numero, 0, 1),
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

function makeLignePDG(Transaction $tx, Compte $compte, float $debit, float $credit): TransactionLigne
{
    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'operation_id' => null,
        'seance' => null,
        'montant' => max($debit, $credit),
        'debit' => $debit,
        'credit' => $credit,
        'compte_id' => $compte->id,
        'tiers_id' => null,
    ]);
}

// ---------------------------------------------------------------------------
// Test 1 : Transaction HelloAsso → pas d'exception (bypass)
// ---------------------------------------------------------------------------

test('[2] HelloAsso — assertComplete ne lève pas d\'exception même si equilibree=false', function () {
    $tx = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'helloasso_order_id' => 12345,
        'equilibree' => false,
    ]);

    expect(fn () => PartieDoubleGuard::assertComplete($tx))->not->toThrow(PartieDoubleIncompleteException::class);
});

// ---------------------------------------------------------------------------
// Test 3 : equilibree=false → lève nonEquilibree
// ---------------------------------------------------------------------------

test('[3] equilibree=false — assertComplete lève PartieDoubleIncompleteException::nonEquilibree', function () {
    $tx = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'equilibree' => false,
        'helloasso_order_id' => null,
    ]);

    expect(fn () => PartieDoubleGuard::assertComplete($tx))
        ->toThrow(PartieDoubleIncompleteException::class, 'equilibree=false');
});

// ---------------------------------------------------------------------------
// Test 4 : equilibree=true mais aucune ligne avec compte_id → lève sansLignes
// ---------------------------------------------------------------------------

test('[4] equilibree=true sans lignes comptables — assertComplete lève PartieDoubleIncompleteException::sansLignes', function () {
    $tx = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'equilibree' => true,
        'helloasso_order_id' => null,
    ]);

    $tx->lignes()->forceDelete();

    expect(fn () => PartieDoubleGuard::assertComplete($tx))
        ->toThrow(PartieDoubleIncompleteException::class, 'aucune ligne comptable');
});

// ---------------------------------------------------------------------------
// Test 5 : lignes PD déséquilibrées → lève desequilibree
// ---------------------------------------------------------------------------

test('[5] lignes PD déséquilibrées — assertComplete lève PartieDoubleIncompleteException::desequilibree', function () {
    $tx = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'equilibree' => true,
        'helloasso_order_id' => null,
    ]);

    $compte = makeComptePDG('607');

    // debit=100, credit=80 → déséquilibré
    makeLignePDG($tx, $compte, 100.00, 0.00);
    makeLignePDG($tx, $compte, 0.00, 80.00);

    expect(fn () => PartieDoubleGuard::assertComplete($tx))
        ->toThrow(PartieDoubleIncompleteException::class, 'PD déséquilibrée');
});

// ---------------------------------------------------------------------------
// Test 6 : lignes PD équilibrées → pas d'exception
// ---------------------------------------------------------------------------

test('[6] lignes PD équilibrées — assertComplete ne lève pas d\'exception', function () {
    $tx = Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
        'equilibree' => true,
        'helloasso_order_id' => null,
    ]);

    $compte = makeComptePDG('512');

    // debit=100, credit=100 → équilibré
    makeLignePDG($tx, $compte, 100.00, 0.00);
    makeLignePDG($tx, $compte, 0.00, 100.00);

    expect(fn () => PartieDoubleGuard::assertComplete($tx))->not->toThrow(PartieDoubleIncompleteException::class);
});
