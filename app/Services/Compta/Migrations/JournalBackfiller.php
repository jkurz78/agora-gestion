<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;

/**
 * Slice 1 « journal de banque ». Peuple transactions.journal :
 *   - ≥1 ligne de compte classe 6/7 → opérationnel (recette=vente, depense=achat) ;
 *   - sinon (trésorerie/bilan seul) → banque.
 * Idempotent : ne touche que les lignes journal IS NULL. Raw SQL (cross-tenant,
 * comme CompteIdBackfiller) + portable SQLite/MySQL (pas d'alias sur la cible).
 */
final class JournalBackfiller
{
    public static function run(): void
    {
        // Passe 1 : transactions ayant au moins une ligne de classe 6 ou 7
        //           → recette = vente, dépense = achat
        DB::statement(<<<'SQL'
            UPDATE transactions
            SET journal = CASE WHEN type = 'recette' THEN 'vente' ELSE 'achat' END
            WHERE journal IS NULL
              AND EXISTS (
                SELECT 1 FROM transaction_lignes tl
                JOIN comptes c ON c.id = tl.compte_id
                WHERE tl.transaction_id = transactions.id
                  AND c.classe IN (6, 7)
                  AND tl.deleted_at IS NULL
              )
        SQL);

        // Passe 2 : toutes les restantes (trésorerie pure / bilan) → banque
        DB::statement(<<<'SQL'
            UPDATE transactions
            SET journal = 'banque'
            WHERE journal IS NULL
        SQL);
    }
}
