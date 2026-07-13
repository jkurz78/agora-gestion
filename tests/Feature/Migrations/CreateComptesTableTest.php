<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedMigrationDatabase;

function withCreateComptesLegacySchema(Closure $fixtures, Closure $test): mixed
{
    return IsolatedMigrationDatabase::run(function () use ($fixtures): void {
        Schema::create('association', fn (Blueprint $table) => $table->id());
        Schema::create('categories', fn (Blueprint $table) => $table->id());
        Schema::create('comptes_bancaires', fn (Blueprint $table) => $table->id());
        Schema::create('sous_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('association_id');
            $table->unsignedBigInteger('categorie_id')->nullable();
            $table->string('nom');
            $table->string('code_cerfa')->nullable();
        });
        Schema::create('usages_sous_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sous_categorie_id');
            $table->string('usage');
        });

        $fixtures();
    }, function () use ($test): mixed {
        $migration = require base_path('database/migrations/2026_05_20_000001_create_comptes_table.php');

        return $test($migration);
    });
}

it('creates and seeds tenant accounts from the complete pre-cutover schema', function (): void {
    withCreateComptesLegacySchema(function (): void {
        DB::table('association')->insert(['id' => 1]);
        DB::table('categories')->insert(['id' => 10]);
        $sousCategorieId = DB::table('sous_categories')->insertGetId([
            'association_id' => 1,
            'categorie_id' => 10,
            'nom' => 'Cotisations',
            'code_cerfa' => '756',
        ]);
        DB::table('usages_sous_categories')->insert([
            'sous_categorie_id' => $sousCategorieId,
            'usage' => 'inscription',
        ]);
    }, function ($migration): void {
        $migration->up();

        expect(Schema::hasColumns('comptes', [
            'association_id', 'numero_pcg', 'intitule', 'classe', 'categorie_id',
            'parent_compte_id', 'actif', 'est_systeme', 'pour_inscriptions',
            'lettrable', 'iban', 'bic', 'domiciliation', 'solde_initial',
            'date_solde_initial', 'compte_bancaire_id', 'deleted_at',
        ]))->toBeTrue();

        $compte = DB::table('comptes')->where('numero_pcg', '756')->first();
        expect($compte)->not->toBeNull()
            ->and((int) $compte->association_id)->toBe(1)
            ->and((int) $compte->classe)->toBe(7)
            ->and((bool) $compte->pour_inscriptions)->toBeTrue();
    });
});

it('blocks creation while a source account has no PCG number', function (): void {
    withCreateComptesLegacySchema(function (): void {
        DB::table('association')->insert(['id' => 1]);
        DB::table('sous_categories')->insert([
            'association_id' => 1,
            'categorie_id' => null,
            'nom' => 'Orpheline',
            'code_cerfa' => null,
        ]);
    }, function ($migration): void {
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'postes historiques n’ont pas de code PCG')
            ->and(Schema::hasTable('comptes'))->toBeFalse();
    });
});

it('keeps identical PCG numbers isolated by tenant and rolls back cleanly', function (): void {
    withCreateComptesLegacySchema(function (): void {
        DB::table('association')->insert([['id' => 1], ['id' => 2]]);
        DB::table('sous_categories')->insert([
            [
                'association_id' => 1,
                'categorie_id' => null,
                'nom' => 'Prestations A',
                'code_cerfa' => '706',
            ],
            [
                'association_id' => 2,
                'categorie_id' => null,
                'nom' => 'Prestations B',
                'code_cerfa' => '706',
            ],
        ]);
    }, function ($migration): void {
        $migration->up();

        expect(DB::table('comptes')->where('numero_pcg', '706')->count())->toBe(2)
            ->and(DB::table('comptes')->where('association_id', 1)->value('intitule'))->toBe('Prestations A')
            ->and(DB::table('comptes')->where('association_id', 2)->value('intitule'))->toBe('Prestations B');

        $migration->down();
        expect(Schema::hasTable('comptes'))->toBeFalse();
    });
});
