<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('has composite index on transaction_lignes(transaction_id, sous_categorie_id)', function () {
    $indexes = Schema::getIndexes('transaction_lignes');
    $indexColumns = collect($indexes)->pluck('columns')->map(fn ($cols) => implode(',', $cols))->toArray();
    expect($indexColumns)->toContain('transaction_id,sous_categorie_id');
});

it('has index on transaction_lignes(operation_id)', function () {
    $indexes = Schema::getIndexes('transaction_lignes');
    $indexColumns = collect($indexes)->pluck('columns')->map(fn ($cols) => implode(',', $cols))->toArray();
    expect($indexColumns)->toContain('operation_id');
});

it('has index on transaction_ligne_affectations(transaction_ligne_id)', function () {
    $indexes = Schema::getIndexes('transaction_ligne_affectations');
    $indexColumns = collect($indexes)->pluck('columns')->map(fn ($cols) => implode(',', $cols))->toArray();
    expect($indexColumns)->toContain('transaction_ligne_id');
});
