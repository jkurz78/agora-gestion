<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedMigrationDatabase;

function withBackfillCompteLegacySchema(Closure $test): mixed
{
    return IsolatedMigrationDatabase::run(function (): void {
        Schema::create('sous_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('association_id');
            $table->string('code_cerfa')->nullable();
        });
        Schema::create('comptes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('association_id');
            $table->string('numero_pcg');
        });
        Schema::create('transaction_lignes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sous_categorie_id')->nullable();
            $table->unsignedBigInteger('compte_id')->nullable();
            $table->softDeletes();
        });
    }, function () use ($test): mixed {
        $migration = require base_path('database/migrations/2026_05_25_000001_backfill_compte_id_in_transaction_lignes.php');

        return $test($migration);
    });
}

it('backfills null compte_id without overwriting a pre-existing different account', function (): void {
    withBackfillCompteLegacySchema(function ($migration): void {
        $sousCategorieId = DB::table('sous_categories')->insertGetId([
            'association_id' => 1,
            'code_cerfa' => '706',
        ]);
        $compteId = DB::table('comptes')->insertGetId([
            'association_id' => 1,
            'numero_pcg' => '706',
        ]);
        $ligneId = DB::table('transaction_lignes')->insertGetId([
            'sous_categorie_id' => $sousCategorieId,
            'compte_id' => null,
        ]);
        $autreCompteId = DB::table('comptes')->insertGetId([
            'association_id' => 1,
            'numero_pcg' => '758',
        ]);
        $lignePreexistanteId = DB::table('transaction_lignes')->insertGetId([
            'sous_categorie_id' => $sousCategorieId,
            'compte_id' => $autreCompteId,
        ]);

        $migration->up();

        expect((int) DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))
            ->toBe($compteId)
            ->and((int) DB::table('transaction_lignes')->where('id', $lignePreexistanteId)->value('compte_id'))
            ->toBe($autreCompteId);
    });
});

it('maps active rows but deliberately excludes resolvable soft-deleted rows', function (): void {
    withBackfillCompteLegacySchema(function ($migration): void {
        $sousCategorieId = DB::table('sous_categories')->insertGetId([
            'association_id' => 1,
            'code_cerfa' => '707',
        ]);
        $compteId = DB::table('comptes')->insertGetId([
            'association_id' => 1,
            'numero_pcg' => '707',
        ]);
        $activeLine = DB::table('transaction_lignes')->insertGetId([
            'sous_categorie_id' => $sousCategorieId,
            'compte_id' => null,
        ]);
        $deletedLine = DB::table('transaction_lignes')->insertGetId([
            'sous_categorie_id' => $sousCategorieId,
            'compte_id' => null,
            'deleted_at' => now(),
        ]);

        $migration->up();

        expect((int) DB::table('transaction_lignes')->where('id', $activeLine)->value('compte_id'))->toBe($compteId)
            ->and(DB::table('transaction_lignes')->where('id', $deletedLine)->value('compte_id'))->toBeNull();
    });
});

it('leaves an active orphan without a matching account unresolved', function (): void {
    withBackfillCompteLegacySchema(function ($migration): void {
        $orphanId = DB::table('sous_categories')->insertGetId([
            'association_id' => 1,
            'code_cerfa' => null,
        ]);
        $ligneId = DB::table('transaction_lignes')->insertGetId([
            'sous_categorie_id' => $orphanId,
            'compte_id' => null,
        ]);

        $migration->up();

        expect(DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))->toBeNull();
    });
});

it('is idempotent and down only resets matching mappings', function (): void {
    withBackfillCompteLegacySchema(function ($migration): void {
        $sousCategorieId = DB::table('sous_categories')->insertGetId([
            'association_id' => 1,
            'code_cerfa' => '606',
        ]);
        $compteId = DB::table('comptes')->insertGetId([
            'association_id' => 1,
            'numero_pcg' => '606',
        ]);
        $ligneId = DB::table('transaction_lignes')->insertGetId([
            'sous_categorie_id' => $sousCategorieId,
            'compte_id' => null,
        ]);

        $migration->up();
        $migration->up();
        expect((int) DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))->toBe($compteId);

        $migration->down();
        expect(DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))->toBeNull();
    });
});
