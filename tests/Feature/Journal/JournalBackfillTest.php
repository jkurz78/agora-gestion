<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\Migrations\JournalBackfiller;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Fixtures helpers
// ---------------------------------------------------------------------------

/**
 * Crée un compte avec la classe donnée dans le tenant courant.
 */
function compteClasseJournalBf(int $classe, string $suffix = ''): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => 'BF'.$classe.$suffix,
        'intitule' => 'Compte classe '.$classe.' '.$suffix,
        'classe' => $classe,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

/**
 * Force journal à NULL sur une transaction (simule l'état pré-backfill).
 */
function forceJournalNull(Transaction $tx): void
{
    DB::table('transactions')->where('id', $tx->id)->update(['journal' => null]);
}

// ---------------------------------------------------------------------------
// Backfill rule tests
// ---------------------------------------------------------------------------

it('[BF1] recette avec ligne classe 7 (+ ligne classe 4) → journal=vente', function () {
    $compte7 = compteClasseJournalBf(7, 'a');
    $compte4 = compteClasseJournalBf(4, 'a');

    $tx = Transaction::factory()->asRecette()->create();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte7->id,
        'debit' => 0.0,
        'credit' => 100.0,
        'montant' => 100.0,
        'sous_categorie_id' => null,
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte4->id,
        'debit' => 100.0,
        'credit' => 0.0,
        'montant' => 100.0,
        'sous_categorie_id' => null,
    ]);

    forceJournalNull($tx);

    JournalBackfiller::run();

    $journal = DB::table('transactions')->where('id', $tx->id)->value('journal');
    expect($journal)->toBe(JournalComptable::Vente->value);
});

it('[BF2] dépense avec ligne classe 6 (+ ligne classe 4) → journal=achat', function () {
    $compte6 = compteClasseJournalBf(6, 'b');
    $compte4 = compteClasseJournalBf(4, 'b');

    $tx = Transaction::factory()->asDepense()->create();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte6->id,
        'debit' => 50.0,
        'credit' => 0.0,
        'montant' => 50.0,
        'sous_categorie_id' => null,
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte4->id,
        'debit' => 0.0,
        'credit' => 50.0,
        'montant' => 50.0,
        'sous_categorie_id' => null,
    ]);

    forceJournalNull($tx);

    JournalBackfiller::run();

    $journal = DB::table('transactions')->where('id', $tx->id)->value('journal');
    expect($journal)->toBe(JournalComptable::Achat->value);
});

it('[BF3] recette avec uniquement des lignes classe 5 → journal=banque', function () {
    $compte5 = compteClasseJournalBf(5, 'c');

    $tx = Transaction::factory()->asRecette()->create();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte5->id,
        'debit' => 0.0,
        'credit' => 200.0,
        'montant' => 200.0,
        'sous_categorie_id' => null,
    ]);

    forceJournalNull($tx);

    JournalBackfiller::run();

    $journal = DB::table('transactions')->where('id', $tx->id)->value('journal');
    expect($journal)->toBe(JournalComptable::Banque->value);
});

it('[BF4] idempotence — deuxième appel ne modifie pas les journaux déjà renseignés', function () {
    $compte7 = compteClasseJournalBf(7, 'd');
    $compte6 = compteClasseJournalBf(6, 'd');
    $compte5 = compteClasseJournalBf(5, 'd');

    // Transaction 1 — vente
    $tx1 = Transaction::factory()->asRecette()->create();
    TransactionLigne::create([
        'transaction_id' => $tx1->id,
        'compte_id' => $compte7->id,
        'debit' => 0.0,
        'credit' => 100.0,
        'montant' => 100.0,
        'sous_categorie_id' => null,
    ]);
    forceJournalNull($tx1);

    // Transaction 2 — achat
    $tx2 = Transaction::factory()->asDepense()->create();
    TransactionLigne::create([
        'transaction_id' => $tx2->id,
        'compte_id' => $compte6->id,
        'debit' => 50.0,
        'credit' => 0.0,
        'montant' => 50.0,
        'sous_categorie_id' => null,
    ]);
    forceJournalNull($tx2);

    // Transaction 3 — banque
    $tx3 = Transaction::factory()->asRecette()->create();
    TransactionLigne::create([
        'transaction_id' => $tx3->id,
        'compte_id' => $compte5->id,
        'debit' => 0.0,
        'credit' => 80.0,
        'montant' => 80.0,
        'sous_categorie_id' => null,
    ]);
    forceJournalNull($tx3);

    // Premier appel
    JournalBackfiller::run();

    $j1after1 = DB::table('transactions')->where('id', $tx1->id)->value('journal');
    $j2after1 = DB::table('transactions')->where('id', $tx2->id)->value('journal');
    $j3after1 = DB::table('transactions')->where('id', $tx3->id)->value('journal');

    expect($j1after1)->toBe(JournalComptable::Vente->value);
    expect($j2after1)->toBe(JournalComptable::Achat->value);
    expect($j3after1)->toBe(JournalComptable::Banque->value);

    // Deuxième appel — résultats identiques
    JournalBackfiller::run();

    expect(DB::table('transactions')->where('id', $tx1->id)->value('journal'))->toBe(JournalComptable::Vente->value);
    expect(DB::table('transactions')->where('id', $tx2->id)->value('journal'))->toBe(JournalComptable::Achat->value);
    expect(DB::table('transactions')->where('id', $tx3->id)->value('journal'))->toBe(JournalComptable::Banque->value);
});
