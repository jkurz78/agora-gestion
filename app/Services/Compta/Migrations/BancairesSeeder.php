<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;

/**
 * Seed helper for Step 4 of plans/fondations-partie-double-slice1.md.
 *
 * Inserts one classe-5 sous-compte per compte_bancaire into `comptes`,
 * numbering them 5121, 5122, 5123… per tenant (PARTITION BY association_id,
 * ORDER BY id ASC — most stable and reproducible ordering).
 *
 * Design decisions baked into this class:
 *
 *  - ROW_NUMBER() OVER (PARTITION BY association_id ORDER BY id) — available on
 *    MySQL 8.0 (Sail / prod) and SQLite 3.25+ (test environment). The row number
 *    (1-based) is appended onto '512' to produce the numero_pcg ('5121', '5122'…).
 *    String concatenation syntax differs between engines: MySQL uses CONCAT(),
 *    SQLite uses ||.
 *
 *  - Idempotence: MySQL uses INSERT IGNORE; SQLite uses INSERT OR IGNORE.
 *    Both skip duplicate rows rejected by the UNIQUE (association_id, numero_pcg)
 *    constraint introduced in Step 3.
 *
 *  - All comptes_bancaires rows are included without a deleted_at filter because
 *    the comptes_bancaires table carries no deleted_at column (the model does not
 *    use SoftDeletes). If a bank account becomes inactive, the corresponding
 *    compte can be deactivated in a later step; ledger presence is orthogonal
 *    to the actif_recettes_depenses UI-selection flag.
 *
 *  - actif is always set TRUE in the seed (do not propagate actif_recettes_depenses
 *    — that flag gates manual-entry form selectors, not ledger existence).
 *
 *  - est_systeme = TRUE, lettrable = FALSE, pour_inscriptions = FALSE per spec §3.2.
 *  - categorie_id = NULL, parent_compte_id = NULL (hierarchy in a later step).
 *  - Bank attributes (iban, bic, domiciliation, solde_initial, date_solde_initial)
 *    are copied verbatim from comptes_bancaires.
 *
 * Extracted out of the migration so the seed can be replayed in tests without
 * re-running the full migration (same pattern as AuditGuard).
 */
final class BancairesSeeder
{
    /**
     * Returns the canonical INSERT … SELECT SQL for the current DB driver.
     *
     * MySQL uses INSERT IGNORE and CONCAT(); SQLite uses INSERT OR IGNORE and ||.
     * Both engines support ROW_NUMBER() OVER (PARTITION BY … ORDER BY …) as of
     * MySQL 8.0 and SQLite 3.25.
     */
    public static function seedSql(): string
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $concatExpr = $isSqlite ? "('512' || r.rang)" : "CONCAT('512', r.rang)";

        return <<<SQL
            {$insertClause} INTO comptes (
                association_id,
                numero_pcg,
                intitule,
                classe,
                categorie_id,
                parent_compte_id,
                actif,
                est_systeme,
                pour_inscriptions,
                lettrable,
                iban,
                bic,
                domiciliation,
                solde_initial,
                date_solde_initial,
                created_at,
                updated_at
            )
            WITH ranked AS (
                SELECT
                    id,
                    association_id,
                    nom,
                    iban,
                    bic,
                    domiciliation,
                    solde_initial,
                    date_solde_initial,
                    ROW_NUMBER() OVER (PARTITION BY association_id ORDER BY id) AS rang
                FROM comptes_bancaires
            )
            SELECT
                r.association_id,
                {$concatExpr},
                r.nom,
                5,
                NULL,
                NULL,
                1,
                1,
                0,
                0,
                r.iban,
                r.bic,
                r.domiciliation,
                r.solde_initial,
                r.date_solde_initial,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM ranked r
            SQL;
    }

    /**
     * Executes the seed statement.
     *
     * Called by the migration's up() and by the test suite's replayBancairesSeed()
     * helper. INSERT IGNORE / INSERT OR IGNORE makes this idempotent.
     */
    public static function seed(): void
    {
        DB::statement(self::seedSql());
    }
}
