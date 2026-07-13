<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\UsageCompte;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function withDc10bLegacyFinalMigrationDatabase(Closure $callback): mixed
{
    $connection = 'dc10b_final_migration_test';
    $originalConnection = DB::getDefaultConnection();

    config()->set("database.connections.{$connection}", [
        ...config('database.connections.sqlite'),
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]);

    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        createDc10bLegacyFinalMigrationSchema();

        return $callback(require base_path(
            'database/migrations/2026_07_12_200001_drop_sous_categories_and_categories.php'
        ));
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
}

function createDc10bLegacyFinalMigrationSchema(): void
{
    Schema::create('association', function (Blueprint $table): void {
        $table->id();
        $table->string('nom')->nullable();
    });
    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('nom')->nullable();
    });
    Schema::create('sous_categories', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
        $table->foreignId('categorie_id')->nullable()->constrained('categories')->nullOnDelete();
        $table->string('code_cerfa')->nullable();
    });
    Schema::create('comptes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
        $table->string('numero_pcg');
        $table->foreignId('categorie_id')->nullable()->constrained('categories')->nullOnDelete();
        $table->softDeletes();
        $table->unique(['association_id', 'numero_pcg']);
    });

    foreach ([
        'transaction_lignes',
        'budget_lines',
        'devis_lignes',
        'facture_lignes',
        'formules_adhesion',
        'helloasso_form_mappings',
        'notes_de_frais_lignes',
        'provisions',
        'type_operations',
        'encadrement_previsions',
    ] as $tableName) {
        Schema::create($tableName, function (Blueprint $table) use ($tableName): void {
            $table->id();
            $table->foreignId('sous_categorie_id')->nullable()->constrained('sous_categories')->nullOnDelete();
            $table->foreignId('compte_id')->nullable()->constrained('comptes')->nullOnDelete();

            if ($tableName === 'transaction_lignes') {
                $table->unsignedBigInteger('transaction_id')->default(1);
                $table->softDeletes();
                $table->index(['transaction_id', 'sous_categorie_id'], 'tl_tx_sc_idx');
            }

            if ($tableName === 'formules_adhesion') {
                $table->index('sous_categorie_id', 'formules_adhesion_souscat_idx');
            }
        });
    }

    Schema::create('helloasso_parametres', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('association_id')->unique()->constrained('association')->cascadeOnDelete();
        $table->foreignId('sous_categorie_don_id')->nullable()->constrained('sous_categories')->nullOnDelete();
        $table->foreignId('compte_don_id')->nullable()->constrained('comptes')->nullOnDelete();
    });

    Schema::create('usages_sous_categories', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
        $table->foreignId('sous_categorie_id')->nullable()->constrained('sous_categories')->cascadeOnDelete();
        $table->foreignId('compte_id')->nullable()->constrained('comptes')->nullOnDelete();
        $table->string('usage', 50);
        $table->unique(['association_id', 'sous_categorie_id', 'usage'], 'usages_sc_unique');
        $table->index(['association_id', 'usage'], 'usages_sc_asso_usage_idx');
    });
}

function createDc10bLegacyMapping(string $numeroPcg, ?string $compteDeletedAt = null): array
{
    $associationId = DB::table('association')->insertGetId(['nom' => 'Association A']);
    $categorieId = DB::table('categories')->insertGetId(['nom' => 'Produits']);
    $sousCategorieId = DB::table('sous_categories')->insertGetId([
        'association_id' => $associationId,
        'categorie_id' => $categorieId,
        'code_cerfa' => $numeroPcg,
    ]);
    $compteId = DB::table('comptes')->insertGetId([
        'association_id' => $associationId,
        'numero_pcg' => $numeroPcg,
        'categorie_id' => $categorieId,
        'deleted_at' => $compteDeletedAt,
    ]);

    return compact('associationId', 'sousCategorieId', 'compteId');
}

it('drops the legacy accounting schema and renames usages', function (): void {
    expect(Schema::hasTable('usages_comptes'))->toBeTrue()
        ->and(Schema::hasTable('usages_sous_categories'))->toBeFalse()
        ->and(Schema::hasTable('sous_categories'))->toBeFalse()
        ->and(Schema::hasTable('categories'))->toBeFalse()
        ->and(Schema::hasColumn('transaction_lignes', 'sous_categorie_id'))->toBeFalse()
        ->and(Schema::hasColumn('comptes', 'categorie_id'))->toBeFalse();
});

it('enforces one usage per tenant account and usage', function (): void {
    $compte = Compte::factory()->create();
    UsageCompte::factory()->for($compte)->create(['usage' => UsageComptable::Don]);

    expect(fn () => UsageCompte::factory()->for($compte)->create([
        'usage' => UsageComptable::Don,
    ]))->toThrow(QueryException::class);
});

it('backfills a soft-deleted transaction line before dropping its legacy attachment', function (): void {
    withDc10bLegacyFinalMigrationDatabase(function (Migration $migration): void {
        ['sousCategorieId' => $sousCategorieId, 'compteId' => $compteId] = createDc10bLegacyMapping('706');
        $ligneId = DB::table('transaction_lignes')->insertGetId([
            'sous_categorie_id' => $sousCategorieId,
            'compte_id' => null,
            'deleted_at' => '2026-01-01 12:00:00',
        ]);

        $migration->up();

        expect((int) DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id'))
            ->toBe($compteId)
            ->and(Schema::hasColumn('transaction_lignes', 'sous_categorie_id'))->toBeFalse();
    });
});

it('backfills the HelloAsso donation account from a soft-deleted tenant account', function (): void {
    withDc10bLegacyFinalMigrationDatabase(function (Migration $migration): void {
        [
            'associationId' => $associationId,
            'sousCategorieId' => $sousCategorieId,
            'compteId' => $compteId,
        ] = createDc10bLegacyMapping('754', '2026-01-01 12:00:00');
        $parametresId = DB::table('helloasso_parametres')->insertGetId([
            'association_id' => $associationId,
            'sous_categorie_don_id' => $sousCategorieId,
            'compte_don_id' => null,
        ]);

        $migration->up();

        expect((int) DB::table('helloasso_parametres')->where('id', $parametresId)->value('compte_don_id'))
            ->toBe($compteId)
            ->and(Schema::hasColumn('helloasso_parametres', 'sous_categorie_don_id'))->toBeFalse();
    });
});

it('blocks the drop when the HelloAsso donation account cannot be resolved in its tenant', function (): void {
    withDc10bLegacyFinalMigrationDatabase(function (Migration $migration): void {
        $associationId = DB::table('association')->insertGetId(['nom' => 'Association A']);
        $otherAssociationId = DB::table('association')->insertGetId(['nom' => 'Association B']);
        $categorieId = DB::table('categories')->insertGetId(['nom' => 'Produits']);
        $sousCategorieId = DB::table('sous_categories')->insertGetId([
            'association_id' => $associationId,
            'categorie_id' => $categorieId,
            'code_cerfa' => '754',
        ]);
        DB::table('comptes')->insert([
            'association_id' => $otherAssociationId,
            'numero_pcg' => '754',
            'categorie_id' => $categorieId,
        ]);
        DB::table('helloasso_parametres')->insert([
            'association_id' => $associationId,
            'sous_categorie_don_id' => $sousCategorieId,
            'compte_don_id' => null,
        ]);

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'helloasso_parametres: 1')
            ->and(Schema::hasColumn('helloasso_parametres', 'sous_categorie_don_id'))->toBeTrue();
    });
});

it('enforces a non-null account and cascades deleted accounts on usages', function (): void {
    $compte = Compte::factory()->create();
    $usage = UsageCompte::factory()->for($compte)->create();

    expect(fn () => DB::table('usages_comptes')->insert([
        'association_id' => $compte->association_id,
        'compte_id' => null,
        'usage' => UsageComptable::Cotisation->value,
    ]))->toThrow(QueryException::class);

    DB::table('comptes')->where('id', $compte->id)->delete();

    expect(DB::table('usages_comptes')->where('id', $usage->id)->exists())->toBeFalse();
});

it('keeps the tenant usage lookup index', function (): void {
    $index = collect(Schema::getIndexes('usages_comptes'))
        ->firstWhere('name', 'usages_comptes_asso_usage_idx');

    expect($index)->not->toBeNull()
        ->and($index['columns'])->toBe(['association_id', 'usage'])
        ->and($index['unique'])->toBeFalse();
});

it('does not create a parasite account when the usage factory receives one', function (): void {
    $compte = Compte::factory()->create();
    $countBefore = DB::table('comptes')->count();

    UsageCompte::factory()->for($compte)->create();

    expect(DB::table('comptes')->count())->toBe($countBefore);
});
