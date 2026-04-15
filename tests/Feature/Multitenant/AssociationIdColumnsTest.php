<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('group A tables have association_id column (nullable, indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with(['tiers', 'categories', 'sous_categories']);
