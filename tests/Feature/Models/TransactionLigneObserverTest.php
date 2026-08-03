<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * Step 10 of plans/fondations-partie-double-slice1.md.
 *
 * Verifies :
 *   - TransactionLigneObserver::saving() XOR + ni-ni invariants (partie double rows only)
 *   - TransactionLigne::isLettree()
 *   - Accessor montantSigne (debit - credit)
 *   - compte() BelongsTo relation
 *   - transaction() BelongsTo regression
 *   - Raw DB insert bypasses observer (documentation test)
 */

// ---------------------------------------------------------------------------
// Helper : insert a minimal Compte for the current tenant
// ---------------------------------------------------------------------------

function tlObserverMakeCompte(string $numero = '706'): Compte
{
    $association = TenantContext::current();

    $id = DB::table('comptes')->insertGetId([
        'association_id' => $association->id,
        'numero_pcg' => $numero,
        'intitule' => "Compte test {$numero}",
        'classe' => (int) substr($numero, 0, 1),
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Compte::find($id);
}

// ---------------------------------------------------------------------------
// Helper : insert a minimal Transaction parent
// ---------------------------------------------------------------------------

function tlObserverMakeTransaction(): Transaction
{
    return Transaction::factory()->create([
        'association_id' => TenantContext::current()->id,
    ]);
}

// ---------------------------------------------------------------------------
// Helper : minimal valid final-schema line payload
// ---------------------------------------------------------------------------

function tlObserverPayload(Transaction $tx, Compte $compte): array
{
    return [
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => '50.00',
        'debit' => '50.00',
        'credit' => '0.00',
    ];
}

// ---------------------------------------------------------------------------
// 1. Valid debit-only partie double ligne saves successfully
// ---------------------------------------------------------------------------

it('observer allows debit-only partie double ligne', function () {
    $compte = tlObserverMakeCompte('706');
    $tx = tlObserverMakeTransaction();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => '100.00',
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
    ]);

    expect($ligne->exists)->toBeTrue();
    expect((float) $ligne->debit)->toBe(100.0);
    expect((float) $ligne->credit)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// 2. Valid credit-only partie double ligne saves successfully
// ---------------------------------------------------------------------------

it('observer allows credit-only partie double ligne', function () {
    $compte = tlObserverMakeCompte('411');
    $tx = tlObserverMakeTransaction();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => '100.00',
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '100.00',
    ]);

    expect($ligne->exists)->toBeTrue();
    expect((float) $ligne->debit)->toBe(0.0);
    expect((float) $ligne->credit)->toBe(100.0);
});

// ---------------------------------------------------------------------------
// 3. Observer rejects partie double ligne with debit > 0 AND credit > 0
// ---------------------------------------------------------------------------

it('observer rejects XOR violation (debit > 0 and credit > 0)', function () {
    $compte = tlObserverMakeCompte('706');
    $tx = tlObserverMakeTransaction();

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => '100.00',
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '50.00',
    ]);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// 4. Observer rejects partie double ligne with debit = 0 AND credit = 0 (ni-ni)
// ---------------------------------------------------------------------------

it('observer rejects ni-ni violation (debit = 0 and credit = 0 with compte_id set)', function () {
    $compte = tlObserverMakeCompte('706');
    $tx = tlObserverMakeTransaction();

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => '100.00',
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '0.00',
    ]);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// 5. isLettree() returns true when lettrage_code is set, false when null
// ---------------------------------------------------------------------------

it('isLettree returns true when lettrage_code is set', function () {
    $tx = tlObserverMakeTransaction();
    $compte = tlObserverMakeCompte('706');

    $ligne = TransactionLigne::create(tlObserverPayload($tx, $compte));
    $ligne->lettrage_code = 'AA';
    $ligne->save();

    expect($ligne->isLettree())->toBeTrue();
});

it('isLettree returns false when lettrage_code is null', function () {
    $tx = tlObserverMakeTransaction();
    $compte = tlObserverMakeCompte('706');

    $ligne = TransactionLigne::create(tlObserverPayload($tx, $compte));

    expect($ligne->isLettree())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 6. Accessor montantSigne returns debit - credit
// ---------------------------------------------------------------------------

it('montant_signe is positive when debit exceeds credit', function () {
    $compte = tlObserverMakeCompte('706');
    $tx = tlObserverMakeTransaction();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => '100.00',
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
    ]);

    expect($ligne->montant_signe)->toBe(100.0);
});

it('montant_signe is negative when credit exceeds debit', function () {
    $compte = tlObserverMakeCompte('411');
    $tx = tlObserverMakeTransaction();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => '50.00',
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '50.00',
    ]);

    expect($ligne->montant_signe)->toBe(-50.0);
});

// ---------------------------------------------------------------------------
// 7. compte() BelongsTo relation
// ---------------------------------------------------------------------------

it('compte() relation returns the associated Compte', function () {
    $compte = tlObserverMakeCompte('706');
    $tx = tlObserverMakeTransaction();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => '100.00',
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
    ]);

    expect($ligne->compte)->not->toBeNull();
    expect($ligne->compte->is($compte))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 8. transaction() BelongsTo regression
// ---------------------------------------------------------------------------

it('transaction() relation still works after enrichment', function () {
    $tx = tlObserverMakeTransaction();
    $compte = tlObserverMakeCompte('706');

    $ligne = TransactionLigne::create(tlObserverPayload($tx, $compte));

    expect($ligne->transaction)->not->toBeNull();
    expect($ligne->transaction->is($tx))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 9. Raw DB insert bypasses observer (documentation test)
//     Even with compte_id set + invalid combo (debit=0, credit=0), no exception.
// ---------------------------------------------------------------------------

it('raw DB insert bypasses Eloquent observer even with invalid partie double combo', function () {
    $compte = tlObserverMakeCompte('511');
    $tx = tlObserverMakeTransaction();

    // This would throw InvalidArgumentException via Eloquent, but raw SQL bypasses observers.
    DB::table('transaction_lignes')->insert([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'montant' => '99.00',
        'debit' => '0.00',
        'credit' => '0.00',
        // No timestamps needed (timestamps = false on the model)
    ]);

    $count = DB::table('transaction_lignes')
        ->where('transaction_id', $tx->id)
        ->where('compte_id', $compte->id)
        ->count();

    expect($count)->toBe(1);
});
