<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill helper for Step 36 of plans/fondations-partie-double-slice1.md (sous-slice 1d).
 *
 * Populates `transaction_lignes.compte_id` from the mapping
 *   sous_categorie_id → sous_categories.code_cerfa → comptes.numero_pcg (même tenant).
 *
 * Design decisions:
 *
 *  - MySQL supports UPDATE ... INNER JOIN ... SET; SQLite requires UPDATE ... WHERE id IN (SELECT ...).
 *    Both SQL variants produce identical results.
 *
 *  - The WHERE clause (compte_id IS NULL AND sous_categorie_id IS NOT NULL) makes the up()
 *    idempotent: running it twice is a no-op for already-backfilled rows.
 *
 *  - Orphaned rows (SC with NULL code_cerfa, or code_cerfa with no matching compte) are
 *    silently skipped by the INNER JOIN — their compte_id remains NULL. A warning is logged
 *    with the count so operators can investigate.
 *
 *  - down() resets compte_id to NULL only for rows that were matched by the forward join,
 *    preserving any compte_id that was set by other means (e.g. the partie-double backfill
 *    from Step 32).
 */
final class CompteIdBackfiller
{
    /**
     * Run the backfill: populate compte_id from sous_categorie_id.
     * Returns the number of rows updated.
     */
    public static function up(): int
    {
        $affected = DB::affectingStatement(self::upSql());

        self::logOrphans();

        return $affected;
    }

    /**
     * Rollback: reset compte_id to NULL for rows that were matched by the forward join.
     */
    public static function down(): void
    {
        DB::statement(self::downSql());
    }

    /**
     * Returns the backfill SQL for the current DB driver.
     *
     * MySQL: UPDATE ... INNER JOIN ... SET (compact, efficient with indexes)
     * SQLite: UPDATE ... WHERE id IN (SELECT ...) (subquery form required)
     */
    public static function upSql(): string
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

        // MySQL: efficient multi-table UPDATE
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

    /**
     * Returns the rollback SQL for the current DB driver.
     */
    public static function downSql(): string
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

    /**
     * Logs a warning for orphaned transaction lines (those that still have
     * sous_categorie_id NOT NULL and compte_id NULL after the backfill ran).
     *
     * These lines have a sous_categorie without code_cerfa, or a code_cerfa
     * that has no matching compte for the tenant. They require manual review.
     */
    private static function logOrphans(): void
    {
        $count = DB::table('transaction_lignes')
            ->whereNotNull('sous_categorie_id')
            ->whereNull('compte_id')
            ->whereNull('deleted_at')
            ->count();

        if ($count > 0) {
            Log::warning('[Step 36] Backfill compte_id: '.$count.' ligne(s) orpheline(s) '.
                '(sous_categorie_id non null, compte_id toujours null). '.
                'Vérifier que toutes les sous-catégories ont un code_cerfa mappé sur un compte PCG.');
        }
    }
}
