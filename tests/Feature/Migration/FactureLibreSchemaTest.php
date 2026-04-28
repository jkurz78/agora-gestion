<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

// ── factures ──────────────────────────────────────────────────────────────────

it('factures has devis_id column (nullable)', function () {
    expect(Schema::hasColumn('factures', 'devis_id'))->toBeTrue();

    $column = collect(Schema::getColumns('factures'))->firstWhere('name', 'devis_id');
    expect($column)->not->toBeNull();
    expect($column['nullable'])->toBeTrue();
});

it('factures has mode_paiement_prevu column (string nullable)', function () {
    expect(Schema::hasColumn('factures', 'mode_paiement_prevu'))->toBeTrue();

    $column = collect(Schema::getColumns('factures'))->firstWhere('name', 'mode_paiement_prevu');
    expect($column)->not->toBeNull();
    expect($column['nullable'])->toBeTrue();
    // SQLite reports 'text' for string columns, MySQL reports 'varchar'
    expect($column['type_name'])->toBeIn(['varchar', 'text']);
});

it('factures has composite index on (association_id, devis_id)', function () {
    $indexes = collect(Schema::getIndexes('factures'));

    $composite = $indexes->first(
        fn ($i) => in_array('association_id', $i['columns'], true)
            && in_array('devis_id', $i['columns'], true)
    );

    expect($composite)->not->toBeNull('Expected a composite index (association_id, devis_id) on factures');
});

it('factures.devis_id FK references devis table with ON DELETE RESTRICT', function () {
    $foreignKeys = collect(Schema::getForeignKeys('factures'));

    $fk = $foreignKeys->first(
        fn ($k) => in_array('devis_id', $k['columns'], true)
    );

    expect($fk)->not->toBeNull('Expected a FK on factures.devis_id');
    expect($fk['foreign_table'])->toBe('devis');
    expect(strtolower((string) $fk['on_delete']))->toBe('restrict');
});

// ── facture_lignes — new columns ──────────────────────────────────────────────

it('facture_lignes has prix_unitaire column (decimal nullable)', function () {
    expect(Schema::hasColumn('facture_lignes', 'prix_unitaire'))->toBeTrue();

    $column = collect(Schema::getColumns('facture_lignes'))->firstWhere('name', 'prix_unitaire');
    expect($column)->not->toBeNull();
    expect($column['nullable'])->toBeTrue();
    // SQLite reports 'numeric', MySQL reports 'decimal' — both map to a decimal column
    expect($column['type_name'])->toBeIn(['decimal', 'numeric']);
    // Full type string contains precision/scale on MySQL, e.g. "decimal(12,2)"
    // On SQLite the type is just "numeric" — skip precision assertion for driver-agnostic test
    // The precision is enforced by the migration definition; MySQL-level check can be done via integration
});

it('facture_lignes has quantite column (decimal nullable)', function () {
    expect(Schema::hasColumn('facture_lignes', 'quantite'))->toBeTrue();

    $column = collect(Schema::getColumns('facture_lignes'))->firstWhere('name', 'quantite');
    expect($column)->not->toBeNull();
    expect($column['nullable'])->toBeTrue();
    // SQLite reports 'numeric', MySQL reports 'decimal'
    expect($column['type_name'])->toBeIn(['decimal', 'numeric']);
});

it('facture_lignes has sous_categorie_id column (nullable)', function () {
    expect(Schema::hasColumn('facture_lignes', 'sous_categorie_id'))->toBeTrue();

    $column = collect(Schema::getColumns('facture_lignes'))->firstWhere('name', 'sous_categorie_id');
    expect($column)->not->toBeNull();
    expect($column['nullable'])->toBeTrue();
});

it('facture_lignes has operation_id column (nullable)', function () {
    expect(Schema::hasColumn('facture_lignes', 'operation_id'))->toBeTrue();

    $column = collect(Schema::getColumns('facture_lignes'))->firstWhere('name', 'operation_id');
    expect($column)->not->toBeNull();
    expect($column['nullable'])->toBeTrue();
});

it('facture_lignes has seance column (int nullable)', function () {
    expect(Schema::hasColumn('facture_lignes', 'seance'))->toBeTrue();

    $column = collect(Schema::getColumns('facture_lignes'))->firstWhere('name', 'seance');
    expect($column)->not->toBeNull();
    expect($column['nullable'])->toBeTrue();
    // SQLite reports 'integer', MySQL reports 'int'
    expect($column['type_name'])->toBeIn(['int', 'integer']);
});

// ── facture_lignes — existing columns untouched ───────────────────────────────

it('facture_lignes still has all original columns', function () {
    expect(Schema::hasColumns('facture_lignes', [
        'id',
        'facture_id',
        'transaction_ligne_id',
        'type',
        'libelle',
        'montant',
        'ordre',
    ]))->toBeTrue();
});
