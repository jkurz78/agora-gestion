<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Tâche 4 — schéma helloasso_line_key / helloasso_discount_code.
 *
 * L'ancien index tl_ha_item_option_unique, UNIQUE sur (helloasso_item_id,
 * helloasso_option_id), ne protège rien : sous MySQL, NULL != NULL dans un
 * index unique, donc plusieurs lignes (item, NULL) — c'est-à-dire plusieurs
 * lignes "parent" pour le même item — coexistent sans être rejetées.
 *
 * helloasso_line_key introduit un discriminant non nul pour toute ligne
 * HelloAsso ('parent' | 'discount' | 'option:{id}'), qui rend l'unicité
 * (helloasso_item_id, helloasso_line_key) réellement contraignante.
 */
it('rejette deux lignes parentes pour le même item', function (): void {
    $transaction = Transaction::factory()->create();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'helloasso_item_id' => 99001,
        'helloasso_line_key' => 'parent',
    ]);

    expect(fn () => TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'helloasso_item_id' => 99001,
        'helloasso_line_key' => 'parent',
    ]))->toThrow(QueryException::class);
});

it('accepte parent, discount et option:18596 sur le même item', function (): void {
    $transaction = Transaction::factory()->create();

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'helloasso_item_id' => 99002,
        'helloasso_line_key' => 'parent',
    ]);

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'helloasso_item_id' => 99002,
        'helloasso_line_key' => 'discount',
        'helloasso_discount_code' => 'PROMO2026',
    ]);

    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'helloasso_item_id' => 99002,
        'helloasso_line_key' => 'option:18596',
    ]);

    expect(TransactionLigne::where('helloasso_item_id', 99002)->count())->toBe(3);
});

it('refuse une ligne HelloAsso sans line_key', function (): void {
    $transaction = Transaction::factory()->create();

    expect(fn () => DB::table('transaction_lignes')->insert([
        'transaction_id' => $transaction->id,
        'montant' => 10,
        'helloasso_item_id' => 99003,
        'helloasso_line_key' => null,
    ]))->toThrow(QueryException::class);
})->skip(fn () => DB::getDriverName() === 'sqlite', 'Contrainte CHECK vérifiée sur MySQL');

it('accepte une ligne manuelle sans item ni line_key', function (): void {
    $transaction = Transaction::factory()->create();

    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
    ]);

    expect($ligne->fresh())
        ->helloasso_item_id->toBeNull()
        ->helloasso_line_key->toBeNull();
});
