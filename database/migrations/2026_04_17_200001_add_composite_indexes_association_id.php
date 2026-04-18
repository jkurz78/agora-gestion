<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes composites (association_id, X) pour accélérer les requêtes de listing
 * tenant-scopées les plus fréquentes.
 *
 * Vérification des colonnes avant écriture (lecture des migrations originales) :
 *
 * - operations  : PAS de colonne `date`. Colonnes disponibles : date_debut, date_fin, statut, nom.
 *                 → Adapté : (association_id, date_debut) — utilisé dans scopeForExercice() et tri par défaut.
 *                   Plan suggérait (association_id, date) — colonne absente.
 *
 * - transactions: colonne `date` confirmée (create_transactions_unified).
 *                 → (association_id, date) — conforme au plan.
 *
 * - exercices   : colonne `annee` confirmée. Mais un index UNIQUE (association_id, annee) existe déjà
 *                 (migration 2026_04_15_100040_add_unique_composites_multi_tenant.php).
 *                 → SKIPPED : un UNIQUE index couvre déjà les requêtes sur cette colonne.
 *
 * - factures    : colonne `statut` confirmée.
 *                 → (association_id, statut) — conforme au plan.
 *
 * - tiers       : colonne `nom` confirmée.
 *                 → (association_id, nom) — conforme au plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // operations : date_debut (adapté depuis le `date` du plan — colonne absente)
        Schema::table('operations', function (Blueprint $t): void {
            $t->index(['association_id', 'date_debut'], 'ops_assoc_date_debut_idx');
        });

        // transactions : date (conforme au plan)
        Schema::table('transactions', function (Blueprint $t): void {
            $t->index(['association_id', 'date'], 'trx_assoc_date_idx');
        });

        // exercices : SKIPPED — (association_id, annee) UNIQUE déjà présent depuis migration 100040

        // factures : statut (conforme au plan)
        Schema::table('factures', function (Blueprint $t): void {
            $t->index(['association_id', 'statut'], 'fac_assoc_statut_idx');
        });

        // tiers : nom (conforme au plan)
        Schema::table('tiers', function (Blueprint $t): void {
            $t->index(['association_id', 'nom'], 'tiers_assoc_nom_idx');
        });
    }

    public function down(): void
    {
        Schema::table('operations', fn (Blueprint $t) => $t->dropIndex('ops_assoc_date_debut_idx'));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropIndex('trx_assoc_date_idx'));
        Schema::table('factures', fn (Blueprint $t) => $t->dropIndex('fac_assoc_statut_idx'));
        Schema::table('tiers', fn (Blueprint $t) => $t->dropIndex('tiers_assoc_nom_idx'));
    }
};
