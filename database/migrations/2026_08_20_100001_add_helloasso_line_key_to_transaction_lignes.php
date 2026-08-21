<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tâche 4 (709A) — discriminant helloasso_line_key.
 *
 * Pourquoi l'ancien index tl_ha_item_option_unique ne protégeait rien
 * ─────────────────────────────────────────────────────────────────────
 * Il pose UNIQUE (helloasso_item_id, helloasso_option_id), et les deux
 * colonnes sont nullables. Sous MySQL comme sous SQLite, NULL n'est jamais
 * égal à NULL — y compris dans un index unique : plusieurs lignes
 * (item_id=X, option_id=NULL) coexistent sans être rejetées. C'est
 * précisément le couple que porte la ligne "parent" d'un item. La garde
 * réelle contre les doublons de ligne parente était donc entièrement
 * portée par le code (HelloAssoSyncService::upsertLigne, lookup explicite
 * whereNull('helloasso_option_id')), jamais par le schéma.
 *
 * Le chantier 709A ajoute un troisième type de ligne — la ligne de remise,
 * qui porte le même item et pas d'option, donc le même couple (item, NULL)
 * que la ligne parente. Ajouter une troisième colonne nullable à la clé
 * (item, option, ???) ne changerait rien à la faille : il faut un
 * discriminant qui ne soit JAMAIS NULL pour une ligne HelloAsso, quel que
 * soit son type (parent / discount / option:{id}).
 *
 * L'ordre des opérations, et pourquoi SQLite ne le révèle pas
 * ─────────────────────────────────────────────────────────────────────
 * 1. Ajout des deux colonnes, nullables — condition préalable à tout backfill.
 * 2. Backfill — sans lui, le premier re-sync HelloAsso après déploiement ne
 *    retrouve plus aucune ligne existante (le lookup côté code portera sur
 *    helloasso_line_key dès la Tâche 5) : chaque commande déjà synchronisée
 *    serait dupliquée en base au lieu d'être mise à jour.
 * 3. Contrôle explicite des doublons AVANT de créer l'index unique. Si le
 *    backfill produit des doublons (données historiques incohérentes), on
 *    veut un message lisible listant les couples en cause — pas le SQLSTATE
 *    23000 opaque que lèverait la création de l'index en production.
 * 4. Création du nouvel index unique — nommé, pour rester lisible dans
 *    `SHOW INDEX` / les erreurs de contrainte futures.
 * 5. Suppression de l'ancien index, devenu redondant et trompeur (il laisse
 *    croire à une protection qu'il n'assure pas).
 * 6. Contrainte CHECK, MySQL uniquement : SQLite ne permet pas d'ajouter un
 *    CHECK à une table existante sans la recréer entièrement (pas de
 *    `ALTER TABLE ... ADD CONSTRAINT` sur CHECK). La suite SQLite reste donc
 *    silencieuse sur ce point — le test correspondant est skip sous SQLite
 *    et vérifié sur MySQL (voir tests/Feature/HelloAsso/LigneKeyMigrationTest.php
 *    et phpunit.mysql.xml).
 *
 * Ne pas réordonner : sur MySQL, laisser des doublons percoler jusqu'à la
 * création de l'index (au lieu de les détecter au 3) ferait échouer la
 * migration avec une erreur sans contexte exploitable en production.
 */
return new class extends Migration
{
    private const string UNIQUE_INDEX = 'tl_ha_item_line_key_unique';

    private const string LEGACY_INDEX = 'tl_ha_item_option_unique';

    private const string CHECK_CONSTRAINT = 'chk_tl_helloasso_line_key_presence';

    public function up(): void
    {
        // 1. Colonnes, nullables.
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->string('helloasso_line_key', 30)->nullable()->after('helloasso_tier_id');
            $table->string('helloasso_discount_code', 50)->nullable()->after('helloasso_line_key');
        });

        // 2. Backfill à partir des colonnes existantes.
        $this->backfillLineKey();

        // 3. Contrôle de doublons — message lisible plutôt qu'un SQLSTATE opaque.
        $this->assertNoDuplicates();

        // 4. Nouvel index unique, puis 5. suppression de l'ancien, désormais redondant.
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->unique(['helloasso_item_id', 'helloasso_line_key'], self::UNIQUE_INDEX);
            $table->dropUnique(self::LEGACY_INDEX);
        });

        // 6. CHECK — MySQL uniquement (SQLite ne sait pas l'ajouter après coup).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                ALTER TABLE transaction_lignes
                ADD CONSTRAINT '.self::CHECK_CONSTRAINT.' CHECK (
                    (helloasso_item_id IS NULL     AND helloasso_line_key IS NULL)
                 OR (helloasso_item_id IS NOT NULL AND helloasso_line_key IS NOT NULL)
                )
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE transaction_lignes DROP CONSTRAINT '.self::CHECK_CONSTRAINT);
        }

        // Recréer l'ancien index avant de supprimer le nouveau et les colonnes.
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->unique(['helloasso_item_id', 'helloasso_option_id'], self::LEGACY_INDEX);
            $table->dropUnique(self::UNIQUE_INDEX);
        });

        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->dropColumn(['helloasso_line_key', 'helloasso_discount_code']);
        });
    }

    /**
     * Dérive helloasso_line_key des colonnes existantes :
     *  - item non nul + option nulle     → 'parent'
     *  - item non nul + option non nulle → 'option:{option_id}'
     *  - item nul (ligne manuelle)       → reste NULL
     *
     * CONCAT() n'existe pas sous SQLite (qui utilise l'opérateur `||`) : la
     * concaténation est donc choisie selon le moteur plutôt qu'écrite en dur.
     */
    private function backfillLineKey(): void
    {
        DB::table('transaction_lignes')
            ->whereNotNull('helloasso_item_id')
            ->whereNull('helloasso_option_id')
            ->update(['helloasso_line_key' => 'parent']);

        $concatOption = DB::getDriverName() === 'sqlite'
            ? "'option:' || helloasso_option_id"
            : "CONCAT('option:', helloasso_option_id)";

        DB::table('transaction_lignes')
            ->whereNotNull('helloasso_item_id')
            ->whereNotNull('helloasso_option_id')
            ->update(['helloasso_line_key' => DB::raw($concatOption)]);
    }

    /**
     * Contrôle des doublons (helloasso_item_id, helloasso_line_key) après backfill.
     *
     * Lève une exception listant les couples en cause plutôt que de laisser
     * la création de l'index unique échouer avec un SQLSTATE 23000 sans
     * contexte exploitable en production.
     */
    private function assertNoDuplicates(): void
    {
        $doublons = DB::table('transaction_lignes')
            ->select('helloasso_item_id', 'helloasso_line_key', DB::raw('COUNT(*) as occurrences'))
            ->whereNotNull('helloasso_item_id')
            ->groupBy('helloasso_item_id', 'helloasso_line_key')
            ->having('occurrences', '>', 1)
            ->get();

        if ($doublons->isEmpty()) {
            return;
        }

        $detail = $doublons
            ->map(fn ($d): string => "item {$d->helloasso_item_id} / clé '{$d->helloasso_line_key}' : {$d->occurrences} lignes")
            ->implode(' ; ');

        throw new RuntimeException(
            'Migration 2026_08_20_100001 impossible : des lignes transaction_lignes partagent déjà le même '.
            "couple (helloasso_item_id, helloasso_line_key) après backfill — {$detail}. ".
            'Ces doublons doivent être nettoyés manuellement (fusion ou suppression) avant de rejouer la migration.'
        );
    }
};
