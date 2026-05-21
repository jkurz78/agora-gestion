<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * Step 10 of plans/fondations-partie-double-slice1.md.
 *
 * Verifies :
 *   - TransactionLigneObserver::saving() XOR + ni-ni invariants (partie double rows only)
 *   - Discriminator : observer skips legacy rows (compte_id = null)
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
// Helper : minimal legacy ligne payload (sous_categorie_id-only)
// ---------------------------------------------------------------------------

function tlObserverLegacyPayload(Transaction $tx, SousCategorie $sc): array
{
    return [
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '50.00',
        // compte_id intentionally absent / null
    ];
}

// ---------------------------------------------------------------------------
// 1. Valid debit-only partie double ligne saves successfully
// ---------------------------------------------------------------------------

it('observer allows debit-only partie double ligne', function () {
    $compte = tlObserverMakeCompte('706');
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
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
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
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
    $sc = SousCategorie::factory()->create();

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
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
    $sc = SousCategorie::factory()->create();

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '100.00',
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '0.00',
    ]);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------------------
// 5. Observer skips legacy rows (compte_id = null)
// ---------------------------------------------------------------------------

it('observer skips legacy row with compte_id null (debit=0, credit=0)', function () {
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '50.00',
        // compte_id not set → null → observer must skip validation
    ]);

    expect($ligne->exists)->toBeTrue();
    expect($ligne->compte_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// 6. Observer skips legacy row with sous_categorie_id and zero debit/credit
// ---------------------------------------------------------------------------

it('observer skips legacy row with sous_categorie_id and zero debit/credit', function () {
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '75.00',
        'debit' => '0.00',
        'credit' => '0.00',
        // compte_id absent
    ]);

    expect($ligne->exists)->toBeTrue();
    expect($ligne->compte_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// 7. isLettree() returns true when lettrage_code is set, false when null
// ---------------------------------------------------------------------------

it('isLettree returns true when lettrage_code is set', function () {
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create(tlObserverLegacyPayload($tx, $sc));
    $ligne->lettrage_code = 'AA';
    $ligne->save();

    expect($ligne->isLettree())->toBeTrue();
});

it('isLettree returns false when lettrage_code is null', function () {
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create(tlObserverLegacyPayload($tx, $sc));

    expect($ligne->isLettree())->toBeFalse();
});

// ---------------------------------------------------------------------------
// 8. Accessor montantSigne returns debit - credit
// ---------------------------------------------------------------------------

it('montant_signe is positive when debit exceeds credit', function () {
    $compte = tlObserverMakeCompte('706');
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
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
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '50.00',
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '50.00',
    ]);

    expect($ligne->montant_signe)->toBe(-50.0);
});

it('montant_signe is zero when debit equals credit (legacy row with both zero)', function () {
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '0.00',
        // compte_id null → legacy, debit/credit default to 0
    ]);

    expect($ligne->montant_signe)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// 9. compte() BelongsTo relation
// ---------------------------------------------------------------------------

it('compte() relation returns the associated Compte', function () {
    $compte = tlObserverMakeCompte('706');
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '100.00',
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
    ]);

    expect($ligne->compte)->not->toBeNull();
    expect($ligne->compte->is($compte))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 10. transaction() BelongsTo regression
// ---------------------------------------------------------------------------

it('transaction() relation still works after enrichment', function () {
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'montant' => '30.00',
    ]);

    expect($ligne->transaction)->not->toBeNull();
    expect($ligne->transaction->is($tx))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 11. Raw DB insert bypasses observer (documentation test)
//     Even with compte_id set + invalid combo (debit=0, credit=0), no exception.
// ---------------------------------------------------------------------------

it('raw DB insert bypasses Eloquent observer even with invalid partie double combo', function () {
    $compte = tlObserverMakeCompte('511');
    $tx = tlObserverMakeTransaction();
    $sc = SousCategorie::factory()->create();

    // This would throw InvalidArgumentException via Eloquent, but raw SQL bypasses observers.
    DB::table('transaction_lignes')->insert([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
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
