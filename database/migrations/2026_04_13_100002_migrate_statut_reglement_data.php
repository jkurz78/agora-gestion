<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Transactions pointées → statut = pointe
        DB::statement("
            UPDATE transactions
            SET statut_reglement = 'pointe'
            WHERE pointe = 1
              AND deleted_at IS NULL
        ");

        // 2. HelloAsso CB/Prélèvement non pointés → statut = recu
        DB::statement("
            UPDATE transactions
            SET statut_reglement = 'recu'
            WHERE helloasso_order_id IS NOT NULL
              AND mode_paiement IN ('cb', 'prelevement')
              AND pointe = 0
              AND deleted_at IS NULL
        ");

        // 3. Les 3 transactions sur Créances à recevoir → Compte Courant
        DB::statement("
            UPDATE transactions t
            JOIN comptes_bancaires c ON c.id = t.compte_id
            SET t.compte_id = (
                SELECT id FROM comptes_bancaires
                WHERE est_systeme = 0
                  AND nom = 'Compte Courant'
                LIMIT 1
            )
            WHERE c.est_systeme = 1
              AND c.nom = 'Créances à recevoir'
              AND t.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE transactions SET statut_reglement = 'en_attente'");
    }
};
