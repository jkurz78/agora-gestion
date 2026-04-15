<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

function hasUniqueIndexOn(string $table, array $columns): bool
{
    $indexes = collect(Schema::getIndexes($table));

    return $indexes->first(fn ($i) => $i['columns'] === $columns && $i['unique']) !== null;
}

// ─── exercices ───────────────────────────────────────────────────────────────

it('exercices has unique (association_id, annee)', function (): void {
    expect(hasUniqueIndexOn('exercices', ['association_id', 'annee']))->toBeTrue();
});

it('exercices no longer has single-column unique on annee', function (): void {
    expect(hasUniqueIndexOn('exercices', ['annee']))->toBeFalse();
});

// ─── type_operations ─────────────────────────────────────────────────────────

it('type_operations has unique (association_id, nom)', function (): void {
    expect(hasUniqueIndexOn('type_operations', ['association_id', 'nom']))->toBeTrue();
});

it('type_operations no longer has single-column unique on nom', function (): void {
    expect(hasUniqueIndexOn('type_operations', ['nom']))->toBeFalse();
});
