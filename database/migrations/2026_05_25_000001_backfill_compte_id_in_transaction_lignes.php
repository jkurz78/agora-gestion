<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step 36 of plans/fondations-partie-double-slice1.md (sous-slice 1d).
 *
 * Backfills transaction_lignes.compte_id from sous_categorie_id via the mapping:
 *   sous_categories.code_cerfa → comptes.numero_pcg (même association_id).
 *
 * The column compte_id was added in Step 6 (migration 2026_05_20_000004)
 * as a nullable FK. This migration populates it for all existing rows that
 * have a sous_categorie_id pointing to a SousCategorie with a code_cerfa
 * that matches a Compte in the same tenant.
 *
 * Orphaned rows (SC with NULL code_cerfa, or code_cerfa without matching
 * compte) are silently skipped — their compte_id remains NULL. A warning
 * est journalisée par la migration.
 *
 * Idempotent:
 *   - up(): WHERE compte_id IS NULL — running twice is a no-op for already-set rows.
 *   - down(): resets compte_id to NULL for rows matched by the forward join.
 *
 * Note: colonne sous_categorie_id reste présente (drop Step 40 différé, après prod stabilisation).
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::affectingStatement($this->upSql());
        $this->logOrphans();

        // Surface the count in artisan output
        if ($affected > 0) {
            echo "  [Step 36] Backfill compte_id: {$affected} ligne(s) mise(s) à jour.\n";
        }
    }

    public function down(): void
    {
        DB::statement($this->downSql());
    }

    private function upSql(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return <<<'SQL'
                UPDATE transaction_lignes
                SET compte_id = (
                    SELECT c.id
                    FROM sous_categories sc
                    INNER JOIN comptes c
                        ON c.numero_pcg = sc.code_cerfa
                       AND c.association_id = sc.association_id
                    WHERE sc.id = transaction_lignes.sous_categorie_id
                    LIMIT 1
                )
                WHERE compte_id IS NULL
                  AND sous_categorie_id IS NOT NULL
                  AND deleted_at IS NULL
                  AND EXISTS (
                    SELECT 1
                    FROM sous_categories sc
                    INNER JOIN comptes c
                        ON c.numero_pcg = sc.code_cerfa
                       AND c.association_id = sc.association_id
                    WHERE sc.id = transaction_lignes.sous_categorie_id
                )
            SQL;
        }

        return <<<'SQL'
            UPDATE transaction_lignes tl
            INNER JOIN sous_categories sc ON tl.sous_categorie_id = sc.id
            INNER JOIN comptes c
                ON c.numero_pcg = sc.code_cerfa
               AND c.association_id = sc.association_id
            SET tl.compte_id = c.id
            WHERE tl.compte_id IS NULL
              AND tl.sous_categorie_id IS NOT NULL
              AND tl.deleted_at IS NULL
        SQL;
    }

    private function downSql(): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return <<<'SQL'
                UPDATE transaction_lignes
                SET compte_id = NULL
                WHERE sous_categorie_id IS NOT NULL
                  AND compte_id IS NOT NULL
                  AND EXISTS (
                    SELECT 1
                    FROM sous_categories sc
                    INNER JOIN comptes c
                        ON c.numero_pcg = sc.code_cerfa
                       AND c.association_id = sc.association_id
                    WHERE sc.id = transaction_lignes.sous_categorie_id
                      AND c.id = transaction_lignes.compte_id
                )
            SQL;
        }

        return <<<'SQL'
            UPDATE transaction_lignes tl
            INNER JOIN sous_categories sc ON tl.sous_categorie_id = sc.id
            INNER JOIN comptes c
                ON c.numero_pcg = sc.code_cerfa
               AND c.association_id = sc.association_id
            SET tl.compte_id = NULL
            WHERE tl.sous_categorie_id IS NOT NULL
              AND tl.compte_id = c.id
        SQL;
    }

    private function logOrphans(): void
    {
        $count = DB::table('transaction_lignes')
            ->whereNotNull('sous_categorie_id')
            ->whereNull('compte_id')
            ->whereNull('deleted_at')
            ->count();

        if ($count > 0) {
            Log::warning('[Step 36] Backfill compte_id: '.$count.' ligne(s) non mappée(s).');
        }
    }
};
