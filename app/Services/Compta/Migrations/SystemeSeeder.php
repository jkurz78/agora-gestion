<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;

/**
 * Seed helper for Step 5 of plans/fondations-partie-double-slice1.md.
 *
 * Inserts the four system accounts (411, 401, 5112, 530) into `comptes` for
 * every tenant.
 *
 * Design decisions baked into this class:
 *
 *  - One cross-tenant INSERT … SELECT FROM associations is issued per account.
 *
 *  - 530 (Caisse — espèces) used to be conditional on the tenant already having a
 *    live transaction with mode_paiement='especes' (plan § "Critère 530"). That
 *    made the account's existence depend on ordering: a transaction switched to
 *    espèces after the seed had run found no 530 and CompteTresorerieResolver
 *    threw. The account is now seeded like the others.
 *
 *  - Idempotence: MySQL uses INSERT IGNORE; SQLite uses INSERT OR IGNORE.
 *    Both skip rows that would violate the UNIQUE (association_id, numero_pcg)
 *    constraint introduced in Step 3.
 *
 *  - est_systeme = TRUE, lettrable = TRUE, actif = TRUE,
 *    pour_inscriptions = FALSE, parent_compte_id = NULL, bank attrs all NULL
 *    per spec §3.3.
 *
 *  - classe = 4 for 411/401 (comptes tiers), classe = 5 for 5112/530 (caisse/chèques).
 *
 * Extracted out of the migration so the seed can be replayed in tests without
 * re-running the full migration.
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
     * @param  bool  $lettrable  Whether the account supports lettrage (default true; provisions use false)
     */
    public static function unconditionalSql(string $numeroPcg, string $intitule, int $classe, bool $lettrable = true): string
    {
        $isSqlite = DB::getDriverName() === 'sqlite';
        $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $lettrableInt = $lettrable ? 1 : 0;

        // Escape single quotes in intitulé defensively (none expected, but safe to have).
        $safePcg = str_replace("'", "''", $numeroPcg);
        $safeIntitule = str_replace("'", "''", $intitule);

        return <<<SQL
            {$insertClause} INTO comptes (
                association_id,
                numero_pcg,
                intitule,
                classe,
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
                1,
                1,
                0,
                {$lettrableInt},
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
     * Executes all seed statements.
     *
     * Called by the migration's up() and by the test suite's replaySystemeSeed()
     * helper. INSERT IGNORE / INSERT OR IGNORE makes each statement idempotent.
     */
    public static function seed(): void
    {
        // Unconditional: 102 Fonds associatifs sans droit de reprise (classe 1)
        DB::statement(self::unconditionalSql('102', 'Fonds associatifs sans droit de reprise', 1, false));

        // Unconditional: 120 Résultat de l'exercice (excédent) (classe 1)
        DB::statement(self::unconditionalSql('120', 'Résultat de l’exercice (excédent)', 1, false));

        // Unconditional: 129 Résultat de l'exercice (déficit) (classe 1)
        DB::statement(self::unconditionalSql('129', 'Résultat de l’exercice (déficit)', 1, false));

        // Unconditional: 411 Clients (classe 4)
        DB::statement(self::unconditionalSql('411', 'Clients', 4));

        // Unconditional: 401 Fournisseurs (classe 4)
        DB::statement(self::unconditionalSql('401', 'Fournisseurs', 4));

        // Unconditional: 467 Autres comptes débiteurs ou créditeurs (classe 4, compensation interne)
        DB::statement(self::unconditionalSql('467', 'Autres comptes débiteurs ou créditeurs', 4));

        // Unconditional: 5112 Chèques à encaisser (classe 5)
        DB::statement(self::unconditionalSql('5112', 'Chèques à encaisser', 5));

        // Unconditional: 530 Caisse (espèces) (classe 5)
        DB::statement(self::unconditionalSql('530', 'Caisse (espèces)', 5));

        // Unconditional: 486 Charges constatées d'avance (classe 4, provisions — non lettrable)
        DB::statement(self::unconditionalSql('486', 'Charges constatées d\'avance', 4, false));

        // Unconditional: 487 Produits constatés d'avance (classe 4, provisions — non lettrable)
        DB::statement(self::unconditionalSql('487', 'Produits constatés d\'avance', 4, false));

        // Unconditional: 681 Dotations aux amort., dépréciations et provisions (classe 6 — non lettrable)
        DB::statement(self::unconditionalSql('681', 'Dotations aux amort., dépréciations et provisions', 6, false));

        // Unconditional: 781 Reprises sur amort., dépréciations et provisions (classe 7 — non lettrable)
        DB::statement(self::unconditionalSql('781', 'Reprises sur amort., dépréciations et provisions', 7, false));
    }
}
