<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill : remplir reference sur les lignes existantes avant la contrainte NOT NULL
        DB::statement("UPDATE depenses SET reference = CONCAT('IMPORT-MIGRATION-', id) WHERE reference IS NULL");
        DB::statement("UPDATE recettes SET reference = CONCAT('IMPORT-MIGRATION-', id) WHERE reference IS NULL");

        Schema::table('depenses', function (Blueprint $table) {
            $table->string('libelle', 255)->nullable()->change();
            $table->string('reference', 100)->nullable(false)->change();
        });

        Schema::table('recettes', function (Blueprint $table) {
            $table->string('libelle', 255)->nullable()->change();
            $table->string('reference', 100)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Remettre libelle NOT NULL (remplacer les NULL par chaîne vide)
        DB::statement("UPDATE depenses SET libelle = '' WHERE libelle IS NULL");
        DB::statement("UPDATE recettes SET libelle = '' WHERE libelle IS NULL");

        Schema::table('depenses', function (Blueprint $table) {
            $table->string('libelle', 255)->nullable(false)->change();
            $table->string('reference', 100)->nullable()->change();
        });

        Schema::table('recettes', function (Blueprint $table) {
            $table->string('libelle', 255)->nullable(false)->change();
            $table->string('reference', 100)->nullable()->change();
        });
    }
};
