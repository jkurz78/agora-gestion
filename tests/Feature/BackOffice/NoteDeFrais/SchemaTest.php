<?php

declare(strict_types=1);

use App\Models\TransactionLigne;
use Illuminate\Support\Facades\Schema;

test('transaction_lignes has nullable piece_jointe_path column', function (): void {
    expect(Schema::hasColumn('transaction_lignes', 'piece_jointe_path'))->toBeTrue();
});

test('transaction_lignes.piece_jointe_path is nullable', function (): void {
    $column = collect(Schema::getColumns('transaction_lignes'))
        ->firstWhere('name', 'piece_jointe_path');

    expect($column)->not->toBeNull();
    expect($column['nullable'])->toBeTrue();
});

test('notes_de_frais has composite index on association_id and statut', function (): void {
    $index = collect(Schema::getIndexes('notes_de_frais'))
        ->first(fn (array $idx): bool => $idx['name'] === 'ndf_asso_statut_idx');

    expect($index)->not->toBeNull();
    expect($index['columns'])->toContain('association_id');
    expect($index['columns'])->toContain('statut');
});

test('TransactionLigne fillable includes piece_jointe_path', function (): void {
    expect((new TransactionLigne)->getFillable())->toContain('piece_jointe_path');
});
