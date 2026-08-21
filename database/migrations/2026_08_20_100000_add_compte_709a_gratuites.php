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
 * ne le duplique pas — on se contente de lui rattacher l'usage. S'il est
 * soft-deleted, on le restaure : l'index unique interdit d'en créer un second.
 *
 * Aucune configuration manuelle n'est requise après cette migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';
        $insertIgnore = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        // 0. Restaurer un 709A soft-deleted AVANT toute insertion.
        //
        // `comptes_asso_numero_pcg_unique` porte sur (association_id, numero_pcg)
        // seuls — il ne connaît pas deleted_at. Un 709A supprimé occupe donc la
        // place : l'étape 1 ne le voyait pas (elle ne regarde que les lignes
        // actives), son INSERT partait, l'index le refusait, et INSERT IGNORE
        // avalait le refus. L'association restait sans compte de gratuité, sans
        // qu'aucune erreur ne le signale — puis la synchro HelloAsso échouait
        // faute de contrepartie pour ses remises.
        //
        // Ce même index garantit qu'il existe au plus UNE ligne 709A par
        // association : si elle est supprimée, il n'y a pas d'actif à côté, et
        // la restauration est sans ambiguïté. `actif` repasse à 1 pour rendre le
        // compte utilisable — exactement ce que l'INSERT de l'étape 1 aurait créé.
        DB::statement(<<<'SQL'
            UPDATE comptes
            SET deleted_at = NULL, actif = 1, updated_at = CURRENT_TIMESTAMP
            WHERE numero_pcg = '709A'
              AND deleted_at IS NOT NULL
            SQL);

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
