<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chantier 4 — ajoute la valeur 'en_main' à l'enum statut_reglement (additif).
 *
 * Migration additive : aucune valeur existante n'est renommée, donc aucun SQL
 * brut ni filtre n'est cassé. La data-migration 2026_06_04_120100 reclasse
 * ensuite les 'recu' encore en-main vers 'en_main'.
 *
 * sqlite (tests) ignore les contraintes enum → MODIFY conditionné à MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite : pas de contrainte enum, rien à faire
        }

        DB::statement(
            'ALTER TABLE transactions MODIFY COLUMN statut_reglement '
            ."ENUM('en_attente', 'recu', 'pointe', 'en_main') NOT NULL DEFAULT 'en_attente'"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Pré-condition au down : aucune ligne ne doit rester 'en_main'
        // (sinon la valeur tomberait hors enum). On les rebascule en 'recu'.
        DB::table('transactions')->where('statut_reglement', 'en_main')->update(['statut_reglement' => 'recu']);

        DB::statement(
            'ALTER TABLE transactions MODIFY COLUMN statut_reglement '
            ."ENUM('en_attente', 'recu', 'pointe') NOT NULL DEFAULT 'en_attente'"
        );
    }
};
