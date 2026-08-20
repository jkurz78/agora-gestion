<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crée le compte 709A « Gratuités accordées » et le rattache à l'usage Gratuite
 * pour TOUTES les associations existantes.
 *
 * 709A n'est PAS un compte système : c'est un compte de ventilation configurable,
 * au même titre que 754 ou 756. Il est résolu par son usage, jamais par
 * Compte::ofNumeroSysteme().
 *
 * Réutilisation : si une association possède déjà un 709A (saisi à la main), on
 * ne le duplique pas — on se contente de lui rattacher l'usage.
 *
 * Aucune configuration manuelle n'est requise après cette migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';
        $insertIgnore = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        // 1. Créer le compte pour chaque association qui n'en a pas.
        DB::statement(<<<SQL
            {$insertIgnore} INTO comptes (
                association_id, numero_pcg, intitule, classe,
                actif, est_systeme, pour_inscriptions, lettrable,
                created_at, updated_at
            )
            SELECT a.id, '709A', 'Gratuités accordées', 7,
                   1, 0, 0, 0,
                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM association a
            WHERE NOT EXISTS (
                SELECT 1 FROM comptes c
                WHERE c.association_id = a.id
                  AND c.numero_pcg = '709A'
                  AND c.deleted_at IS NULL
            )
            SQL);

        // 2. Rattacher l'usage — y compris sur un 709A préexistant.
        $usage = UsageComptable::Gratuite->value;

        // `usage` est un mot réservé MySQL (privilège GRANT USAGE) — il doit être
        // entre backticks dans le SQL brut. SQLite accepte aussi les backticks
        // (compatibilité MySQL), donc la même requête fonctionne sur les deux
        // moteurs.
        DB::statement(<<<SQL
            {$insertIgnore} INTO usages_comptes (association_id, compte_id, `usage`, created_at, updated_at)
            SELECT c.association_id, c.id, '{$usage}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM comptes c
            WHERE c.numero_pcg = '709A'
              AND c.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM usages_comptes u
                  WHERE u.compte_id = c.id AND u.`usage` = '{$usage}'
              )
            SQL);
    }

    public function down(): void
    {
        // Pas de down() destructif : le 709A peut déjà porter des écritures.
        DB::table('usages_comptes')->where('usage', UsageComptable::Gratuite->value)->delete();
    }
};
