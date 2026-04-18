<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('group A tables have association_id column (indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with(['tiers', 'categories', 'sous_categories']);

it('group B tables have association_id column (indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with([
    'transactions', 'comptes_bancaires', 'remises_bancaires',
    'rapprochements_bancaires', 'virements_internes',
]);

it('group C tables have association_id column (indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with(['operations', 'type_operations', 'participants', 'seances']);

it('group D tables have association_id column (indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with(['factures', 'documents_previsionnels', 'budget_lines', 'exercices', 'provisions']);

it('group E tables have association_id column (indexed)', function (string $table): void {
    expect(Schema::hasColumn($table, 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull();

    $indexes = collect(Schema::getIndexes($table));
    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();
})->with(['email_templates', 'message_templates', 'campagnes_email', 'formulaire_tokens']);

it('group A-E tables have association_id NOT NULL', function (string $table): void {
    $column = collect(Schema::getColumns($table))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeFalse();
})->with([
    'tiers', 'categories', 'sous_categories',
    'transactions', 'comptes_bancaires', 'remises_bancaires', 'rapprochements_bancaires', 'virements_internes',
    'operations', 'type_operations', 'participants', 'seances',
    'factures', 'documents_previsionnels', 'budget_lines', 'exercices', 'provisions',
    'email_templates', 'message_templates', 'campagnes_email', 'formulaire_tokens',
]);

it('sequences table has association_id (not null, indexed, composite unique)', function (): void {
    if (! Schema::hasTable('sequences')) {
        $this->markTestSkipped('sequences table does not exist');
    }

    expect(Schema::hasColumn('sequences', 'association_id'))->toBeTrue();

    $column = collect(Schema::getColumns('sequences'))->firstWhere('name', 'association_id');
    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeFalse();

    $indexes = collect(Schema::getIndexes('sequences'));

    $hasIndex = $indexes->first(fn ($i) => in_array('association_id', $i['columns']));
    expect($hasIndex)->not->toBeNull();

    $hasComposite = $indexes->first(
        fn ($i) => $i['unique'] && in_array('association_id', $i['columns']) && in_array('exercice', $i['columns'])
    );
    expect($hasComposite)->not->toBeNull();
});
