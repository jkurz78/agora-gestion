<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;

/**
 * Seed helper for Step 5 of plans/fondations-partie-double-slice1.md.
 *
 * Inserts the four system accounts (411, 401, 5112, 530) into `comptes` for
 * every tenant. 530 (Caisse — espèces) is conditional: it is only inserted when
 * the tenant has at least one non-deleted transaction with mode_paiement='especes'.
 *
 * Design decisions baked into this class:
 *
 *  - Unconditional accounts (411, 401, 5112): one cross-tenant INSERT … SELECT FROM
 *    associations is issued per account. Symmetric with AuditGuard and BancairesSeeder.
 *
 *  - Conditional account (530): the EXISTS sub-query reads from `transactions`
 *    filtered by association_id, mode_paiement='especes', AND deleted_at IS NULL —
 *    exactly as specified in the plan (§ "Critère 530 — décision actée").
 *
 *  - Idempotence: MySQL uses INSERT IGNORE; SQLite uses INSERT OR IGNORE.
 *    Both skip rows that would violate the UNIQUE (association_id, numero_pcg)
 *    constraint introduced in Step 3.
 *
 *  - est_systeme = TRUE, lettrable = TRUE, categorie_id = NULL, actif = TRUE,
 *    pour_inscriptions = FALSE, parent_compte_id = NULL, bank attrs all NULL
 *    per spec §3.3.
 *
 *  - classe = 4 for 411/401 (comptes tiers), classe = 5 for 5112/530 (caisse/chèques).
 *
 * Extracted out of the migration so the seed can be replayed in tests without
 * re-running the full migration (same pattern as AuditGuard and BancairesSeeder).
 */
final class SystemeSeeder
{
    /**
     * Returns the INSERT … SELECT SQL for the three unconditional system accounts
     * (411, 401, 5112) for the current DB driver.
     *
     * @param  string  $numeroPcg  The account number ('411', '401', or '5112')
     * @param  string  $intitule  French label ('Clients', 'Fournisseurs', 'Chèques à encaisser')
     * @param  int  $classe  PCG class (4 for tiers, 5 for caisse/chèques)
     */
    public static function unconditionalSql(string $numeroPcg, string $intitule, int $classe): string
    {
        $isSqlite = DB::getDriverName() === 'sqlite';
        $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        // Escape single quotes in intitulé defensively (none expected, but safe to have).
        $safePcg = str_replace("'", "''", $numeroPcg);
        $safeIntitule = str_replace("'", "''", $intitule);

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
            SELECT
                a.id,
                '{$safePcg}',
                '{$safeIntitule}',
                {$classe},
                NULL,
                NULL,
                1,
                1,
                0,
                1,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM association a
            SQL;
    }

    /**
     * Returns the INSERT … SELECT SQL for the conditional 530 (Caisse — espèces).
     *
     * Only inserts for associations that satisfy:
     *   EXISTS (SELECT 1 FROM transactions t
     *           WHERE t.association_id = associations.id
     *             AND t.mode_paiement = 'especes'
     *             AND t.deleted_at IS NULL)
     *
     * This is the exact contract from the plan's "Critère 530 — décision actée".
     */
    public static function conditionalCaisseSql(): string
    {
        $isSqlite = DB::getDriverName() === 'sqlite';
        $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

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
            SELECT
                a.id,
                '530',
                'Caisse (espèces)',
                5,
                NULL,
                NULL,
                1,
                1,
                0,
                1,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM association a
            WHERE EXISTS (
                SELECT 1 FROM transactions t
                WHERE t.association_id = a.id
                  AND t.mode_paiement = 'especes'
                  AND t.deleted_at IS NULL
            )
            SQL;
    }

    /**
     * Executes all seed statements.
     *
     * Called by the migration's up() and by the test suite's replaySystemeSeed()
     * helper. INSERT IGNORE / INSERT OR IGNORE makes each statement idempotent.
     */
    public static function seed(): void
    {
        // Unconditional: 411 Clients (classe 4)
        DB::statement(self::unconditionalSql('411', 'Clients', 4));

        // Unconditional: 401 Fournisseurs (classe 4)
        DB::statement(self::unconditionalSql('401', 'Fournisseurs', 4));

        // Unconditional: 5112 Chèques à encaisser (classe 5)
        DB::statement(self::unconditionalSql('5112', 'Chèques à encaisser', 5));

        // Conditional: 530 Caisse (espèces) — only for tenants with live espèces transactions
        DB::statement(self::conditionalCaisseSql());
    }
}
