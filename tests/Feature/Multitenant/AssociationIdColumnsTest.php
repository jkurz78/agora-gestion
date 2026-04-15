<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('group A tables have association_id column (nullable, indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with(['tiers', 'categories', 'sous_categories']);

it('group B tables have association_id column (nullable, indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with([
    'transactions', 'comptes_bancaires', 'remises_bancaires',
    'rapprochements_bancaires', 'virements_internes',
]);

it('group C tables have association_id column (nullable, indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with(['operations', 'type_operations', 'participants', 'seances']);
