<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration B — Suppression des tables legacy dépenses/recettes.
 *
 * À exécuter après validation complète en production de la Migration A
 * (create_transactions_unified). Les données ont été migrées vers transactions,
 * transaction_lignes et transaction_ligne_affectations.
 *
 * Tables supprimées :
 *   - depense_ligne_affectations  (migrées → transaction_ligne_affectations)
 *   - recette_ligne_affectations  (migrées → transaction_ligne_affectations)
 *   - depense_lignes              (migrées → transaction_lignes)
 *   - recette_lignes              (migrées → transaction_lignes)
 *   - depenses                    (migrées → transactions)
 *   - recettes                    (migrées → transactions)
 *   - donateurs                   (migrés  → tiers)
 *   - membres                     (migrés  → tiers)
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMySQL = DB::getDriverName() === 'mysql';
        if ($isMySQL) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            // Affectations d'abord (référencent les lignes)
            Schema::dropIfExists('depense_ligne_affectations');
            Schema::dropIfExists('recette_ligne_affectations');

            // Lignes ensuite (référencent depenses/recettes)
            Schema::dropIfExists('depense_lignes');
            Schema::dropIfExists('recette_lignes');

            // Tables principales
            Schema::dropIfExists('depenses');
            Schema::dropIfExists('recettes');

            // Tables tiers legacy (migrées vers tiers)
            Schema::dropIfExists('donateurs');
            Schema::dropIfExists('membres');
        } finally {
            if ($isMySQL) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    public function down(): void
    {
        // La restauration complète des tables legacy n'est pas supportée —
        // les données source sont dans transactions/transaction_lignes.
        // En cas de rollback nécessaire, restaurer depuis une sauvegarde.
        throw new RuntimeException(
            'Migration B irréversible : restaurer depuis une sauvegarde si nécessaire.'
        );
    }
};
