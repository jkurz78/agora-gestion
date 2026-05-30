<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pre-flight guard for the slice 1 `comptes` migration (Step 3 of
 * plans/fondations-partie-double-slice1.md).
 *
 * Extracted out of the migration class itself so the guard can be unit-tested
 * without rolling back / re-running the migration. The migration's `up()`
 * invokes {@see AuditGuard::assertAuditPassed()} before creating the `comptes`
 * table and seeding it from `sous_categories`.
 *
 * Also owns the canonical INSERT…SELECT statement used to seed `comptes` from
 * `sous_categories` (with usage-derived `pour_inscriptions`). The migration
 * calls {@see AuditGuard::seedFromSousCategoriesSql()} once, and the test
 * suite replays the same SQL after creating fixtures (see
 * tests/Feature/Migrations/CreateComptesTableTest.php).
 *
 * The statement is intentionally portable between MySQL (prod / staging) and
 * SQLite (test env). The CAST target type differs per engine: MySQL only
 * accepts `CAST(... AS SIGNED)` (a bare `AS INTEGER` raises error 1064),
 * whereas SQLite expects `CAST(... AS INTEGER)`. The driver is detected at
 * runtime to emit the correct keyword.
 */
final class AuditGuard
{
    /**
     * Throws if any `sous_categories` row has a NULL `code_cerfa`.
     *
     * The pre-backfill audit command (`php artisan audit:compta-v5-preparation`)
     * surfaces those rows so the operator can fix them before running the
     * migration. The exception message points back to that command.
     */
    public static function assertAuditPassed(): void
    {
        $hasGaps = DB::table('sous_categories')
            ->whereNull('code_cerfa')
            ->exists();

        if ($hasGaps) {
            throw new RuntimeException(
                'Run `php artisan audit:compta-v5-preparation` first and fix sous-catégories without code_cerfa before migrating'
            );
        }
    }

    /**
     * SQL used by the migration (and replayed by the test suite) to seed
     * `comptes` from existing `sous_categories` rows.
     *
     * Decisions baked into this statement:
     *  - `numero_pcg` = `sous_categories.code_cerfa` (skipped if NULL — guarded
     *    by {@see assertAuditPassed()} at migrate time, defensive WHERE here for
     *    the replayed case in tests).
     *  - `classe` derived from the first character of `code_cerfa`.
     *  - `pour_inscriptions` derived from the `usages_sous_categories` pivot
     *    (the legacy boolean column on `sous_categories` was dropped in the
     *    v4.1.2 refactor — see 2026_04_21_120200 migration).
     *  - `lettrable = FALSE`, `est_systeme = FALSE`, `actif = TRUE` per spec
     *    §3.1 — comptes de gestion only at this step.
     *  - Idempotent : an INSERT…SELECT guarded by `NOT EXISTS` so replaying
     *    the statement is a no-op when the row is already there.
     */
    public static function seedFromSousCategoriesSql(): string
    {
        // MySQL n'accepte pas `CAST(... AS INTEGER)` (erreur 1064) — il faut
        // `AS SIGNED`. SQLite, lui, attend `AS INTEGER`. On branche sur le driver.
        $intType = DB::getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        return <<<SQL
            INSERT INTO comptes (
                association_id, numero_pcg, intitule, classe, categorie_id,
                actif, est_systeme, pour_inscriptions, lettrable,
                created_at, updated_at
            )
            SELECT
                sc.association_id,
                sc.code_cerfa,
                sc.nom,
                CAST(SUBSTR(sc.code_cerfa, 1, 1) AS {$intType}),
                sc.categorie_id,
                1,
                0,
                CASE WHEN EXISTS (
                    SELECT 1 FROM usages_sous_categories usc
                    WHERE usc.sous_categorie_id = sc.id
                      AND usc.usage = 'inscription'
                ) THEN 1 ELSE 0 END,
                0,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM sous_categories sc
            WHERE sc.code_cerfa IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM comptes c
                WHERE c.association_id = sc.association_id
                  AND c.numero_pcg = sc.code_cerfa
            )
            SQL;
    }
}
