<?php

declare(strict_types=1);

use App\Models\TransactionLigne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('ajoute la filiation nullable des fractions de poste tiers', function (): void {
    expect(Schema::hasColumn('transaction_lignes', 'poste_tiers_parent_id'))->toBeTrue();

    $parent = TransactionLigne::factory()->create();
    $fraction = TransactionLigne::factory()->create([
        'transaction_id' => (int) $parent->transaction_id,
        'poste_tiers_parent_id' => (int) $parent->id,
    ]);

    expect($fraction->posteTiersParent?->is($parent))->toBeTrue()
        ->and($parent->fractionsPosteTiers()->pluck('id')->all())
        ->toContain((int) $fraction->id);
});

it('refuse une filiation vers une ligne inexistante', function (): void {
    expect(fn () => TransactionLigne::factory()->create([
        'poste_tiers_parent_id' => 999999999,
    ]))->toThrow(QueryException::class);
});
