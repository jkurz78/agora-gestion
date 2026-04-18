<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('associations table has multi-tenant columns', function (): void {
    expect(Schema::hasColumn('association', 'slug'))->toBeTrue()
        ->and(Schema::hasColumn('association', 'exercice_mois_debut'))->toBeTrue()
        ->and(Schema::hasColumn('association', 'statut'))->toBeTrue()
        ->and(Schema::hasColumn('association', 'wizard_completed_at'))->toBeTrue();
});

it('associations slug column is unique', function (): void {
    $indexes = collect(Schema::getIndexes('association'));
    $slugUnique = $indexes->first(fn ($i) => in_array('slug', $i['columns']) && $i['unique']);
    expect($slugUnique)->not->toBeNull();
});

it('association_slug_aliases table exists with expected columns', function (): void {
    expect(Schema::hasTable('association_slug_aliases'))->toBeTrue()
        ->and(Schema::hasColumn('association_slug_aliases', 'slug_ancien'))->toBeTrue()
        ->and(Schema::hasColumn('association_slug_aliases', 'association_id'))->toBeTrue()
        ->and(Schema::hasColumn('association_slug_aliases', 'deprecated_at'))->toBeTrue();

    $indexes = collect(Schema::getIndexes('association_slug_aliases'));
    $slugAncienUnique = $indexes->first(fn ($i) => in_array('slug_ancien', $i['columns']) && $i['unique']);
    expect($slugAncienUnique)->not->toBeNull();
});

it('association_user pivot table has expected structure', function (): void {
    expect(Schema::hasTable('association_user'))->toBeTrue()
        ->and(Schema::hasColumn('association_user', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('association_user', 'association_id'))->toBeTrue()
        ->and(Schema::hasColumn('association_user', 'role'))->toBeTrue()
        ->and(Schema::hasColumn('association_user', 'invited_at'))->toBeTrue()
        ->and(Schema::hasColumn('association_user', 'joined_at'))->toBeTrue()
        ->and(Schema::hasColumn('association_user', 'revoked_at'))->toBeTrue();

    $indexes = collect(Schema::getIndexes('association_user'));
    $unique = $indexes->first(fn ($i) => $i['columns'] === ['user_id', 'association_id'] && $i['unique']);
    expect($unique)->not->toBeNull();

    $roleIdx = $indexes->first(fn ($i) => $i['columns'] === ['association_id', 'role'] && ! $i['unique']);
    expect($roleIdx)->not->toBeNull();
});
