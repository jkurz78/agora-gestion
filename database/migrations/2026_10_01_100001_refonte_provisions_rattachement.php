<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réécriture de la table provisions : elle est vide sur toutes les
     * associations, aucune reprise de données n'est nécessaire. Le `delete`
     * n'est là que pour garantir l'invariant sur une base de développement qui
     * aurait des lignes de test.
     */
    public function up(): void
    {
        DB::table('provisions')->delete();

        Schema::table('provisions', function (Blueprint $table) {
            $table->foreignId('transaction_ligne_id')
                ->nullable()
                ->after('exercice')
                ->constrained('transaction_lignes')
                ->restrictOnDelete();
        });

        Schema::table('provisions', function (Blueprint $table) {
            $table->dropColumn(['type', 'date']);
            $table->string('libelle', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('provisions')->delete();

        Schema::table('provisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_ligne_id');
            $table->string('type', 10)->default('recette');
            $table->date('date')->nullable();
            $table->string('libelle', 255)->nullable(false)->change();
        });
    }
};
