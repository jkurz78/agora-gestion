<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DC-10b-4 : dissolution finale des tables sous_categories et categories.
 *
 * Supprime toutes les colonnes sous_categorie_id (12 tables), comptes.categorie_id,
 * la table pivot usages_sous_categories, puis les tables sous_categories et categories.
 */
return new class extends Migration
{
    private const TABLES_WITH_SOUS_CATEGORIE = [
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
        'usages_sous_categories',
    ];

    public function up(): void
    {
        $this->backfillTransactionLignes();
        $this->assertNoUnresolvedAttachments();

        // SQLite requires indexes referencing a column to be removed before the column.
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->dropIndex('tl_tx_sc_idx');
        });
        Schema::table('formules_adhesion', function (Blueprint $table): void {
            $table->dropIndex('formules_adhesion_souscat_idx');
        });

        foreach (array_diff(self::TABLES_WITH_SOUS_CATEGORIE, ['usages_sous_categories']) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('sous_categorie_id');
            });
        }

        Schema::table('helloasso_parametres', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sous_categorie_don_id');
        });

        Schema::table('comptes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('categorie_id');
        });

        Schema::table('usages_sous_categories', function (Blueprint $table): void {
            $table->dropForeign(['association_id']);
            $table->dropForeign(['sous_categorie_id']);
            $table->dropForeign(['compte_id']);
        });
        Schema::table('usages_sous_categories', function (Blueprint $table): void {
            $table->dropUnique('usages_sc_unique');
            $table->dropIndex('usages_sc_asso_usage_idx');
        });
        Schema::table('usages_sous_categories', function (Blueprint $table): void {
            $table->dropColumn('sous_categorie_id');
        });

        Schema::rename('usages_sous_categories', 'usages_comptes');

        Schema::table('usages_comptes', function (Blueprint $table): void {
            $table->unsignedBigInteger('compte_id')->nullable(false)->change();
        });
        Schema::table('usages_comptes', function (Blueprint $table): void {
            $table->foreign('association_id')->references('id')->on('association')->cascadeOnDelete();
            $table->foreign('compte_id')->references('id')->on('comptes')->cascadeOnDelete();
            $table->unique(['association_id', 'compte_id', 'usage'], 'usages_comptes_unique');
            $table->index(['association_id', 'usage'], 'usages_comptes_asso_usage_idx');
        });

        Schema::dropIfExists('sous_categories');
        Schema::dropIfExists('categories');
    }

    public function down(): void
    {
        // Irreversible — data is lost. Use a backup to restore.
        throw new RuntimeException('This migration is irreversible. Restore from backup.');
    }

    private function backfillTransactionLignes(): void
    {
        $comptesByTenantAndNumber = DB::table('comptes')
            ->get(['id', 'association_id', 'numero_pcg'])
            ->keyBy(fn (object $compte): string => ((int) $compte->association_id).':'.$compte->numero_pcg);

        foreach (DB::table('sous_categories')->get(['id', 'association_id', 'code_cerfa']) as $sousCategorie) {
            if ($sousCategorie->code_cerfa === null) {
                continue;
            }

            $key = ((int) $sousCategorie->association_id).':'.$sousCategorie->code_cerfa;
            $compte = $comptesByTenantAndNumber->get($key);

            if ($compte === null) {
                continue;
            }

            DB::table('transaction_lignes')
                ->where('sous_categorie_id', $sousCategorie->id)
                ->whereNull('compte_id')
                ->update(['compte_id' => $compte->id]);
        }
    }

    private function assertNoUnresolvedAttachments(): void
    {
        $unresolved = [];

        foreach (self::TABLES_WITH_SOUS_CATEGORIE as $tableName) {
            $count = DB::table($tableName)
                ->whereNotNull('sous_categorie_id')
                ->whereNull('compte_id')
                ->count();

            if ($count > 0) {
                $unresolved[$tableName] = $count;
            }
        }

        if ($unresolved !== []) {
            throw new RuntimeException(
                'Migration interrompue : rattachements compte_id non résolus ('.
                collect($unresolved)->map(fn (int $count, string $table): string => "{$table}: {$count}")->implode(', ').
                ').'
            );
        }
    }
};
