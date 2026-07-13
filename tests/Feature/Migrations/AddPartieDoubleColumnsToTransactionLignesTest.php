<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedMigrationDatabase;

function withLegacyTransactionLines(Closure $test): mixed
{
    return IsolatedMigrationDatabase::run(function (): void {
        Schema::create('comptes', fn (Blueprint $table) => $table->id());
        Schema::create('tiers', fn (Blueprint $table) => $table->id());
        Schema::create('transaction_lignes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('sous_categorie_id')->nullable();
            $table->decimal('montant', 12, 2)->default(0);
        });
    }, function () use ($test): mixed {
        $migration = require base_path('database/migrations/2026_05_20_000004_add_partie_double_columns_to_transaction_lignes.php');
        $migration->up();

        return $test($migration);
    });
}

it('adds the partie-double columns and indexes to a pre-cutover table', function (): void {
    withLegacyTransactionLines(function (): void {
        expect(Schema::hasColumns('transaction_lignes', [
            'compte_id', 'debit', 'credit', 'tiers_id', 'lettrage_code', 'libelle',
        ]))->toBeTrue();

        $indexes = collect(Schema::getIndexes('transaction_lignes'))
            ->pluck('columns')
            ->map(fn (array $columns): string => implode(',', $columns));

        expect($indexes)->toContain('compte_id,tiers_id,lettrage_code')
            ->and($indexes)->toContain('compte_id,tiers_id')
            ->and($indexes)->toContain('lettrage_code');
    });
});

it('preserves legacy rows and applies defaults', function (): void {
    withLegacyTransactionLines(function (): void {
        $id = DB::table('transaction_lignes')->insertGetId([
            'transaction_id' => 10,
            'sous_categorie_id' => 20,
            'montant' => 42.50,
        ]);
        $row = DB::table('transaction_lignes')->find($id);

        expect((float) $row->montant)->toBe(42.5)
            ->and((float) $row->debit)->toBe(0.0)
            ->and((float) $row->credit)->toBe(0.0)
            ->and($row->compte_id)->toBeNull()
            ->and($row->tiers_id)->toBeNull();
    });
});

it('rolls back only the six added columns', function (): void {
    withLegacyTransactionLines(function ($migration): void {
        $migration->down();

        expect(Schema::hasColumns('transaction_lignes', ['sous_categorie_id', 'montant']))->toBeTrue()
            ->and(Schema::hasColumn('transaction_lignes', 'compte_id'))->toBeFalse()
            ->and(Schema::hasColumn('transaction_lignes', 'debit'))->toBeFalse();
    });
});
