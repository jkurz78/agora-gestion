<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Étape 1 : ajouter les nouvelles colonnes (formule, dates, notes)
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->foreignId('formule_adhesion_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('formules_adhesion')
                ->nullOnDelete();
            $table->date('date_debut')->nullable()->after('formule_adhesion_id');
            $table->date('date_fin')->nullable()->after('date_debut');
            $table->string('notes', 255)->nullable()->after('date_fin');
            $table->index(['tiers_id', 'date_debut', 'date_fin'], 'adhesions_dates_idx');
        });

        // Étape 2 : copier motif_gratuite → notes
        DB::statement('UPDATE adhesions SET notes = motif_gratuite WHERE motif_gratuite IS NOT NULL');

        // Étape 3 : drop gratuite + motif_gratuite
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->dropColumn(['gratuite', 'motif_gratuite']);
        });

        // Étape 4 : permettre exercice NULL pour les adhésions mode durée
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('exercice')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->dropIndex('adhesions_dates_idx');
            $table->dropConstrainedForeignId('formule_adhesion_id');
            $table->dropColumn(['date_debut', 'date_fin']);
            $table->boolean('gratuite')->default(false)->after('transaction_id');
            $table->string('motif_gratuite', 255)->nullable()->after('gratuite');
        });

        DB::statement('UPDATE adhesions SET motif_gratuite = notes WHERE notes IS NOT NULL');
        DB::statement('UPDATE adhesions SET gratuite = 1 WHERE transaction_id IS NULL');

        Schema::table('adhesions', function (Blueprint $table): void {
            $table->dropColumn('notes');
            $table->unsignedSmallInteger('exercice')->nullable(false)->change();
        });
    }
};
