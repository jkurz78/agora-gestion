<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            // Reclassement manuel d'une imputation : le compte d'origine est
            // conservé (jamais écrasé par un second reclassement), le motif est
            // obligatoire côté service, et `reclassee_at` sert de marque — la
            // synchronisation HelloAsso ne réécrit plus le compte ni l'opération
            // d'une ligne marquée.
            $table->foreignId('reclassement_compte_origine_id')->nullable()->after('libelle')
                ->constrained('comptes')->nullOnDelete();
            $table->string('reclassement_motif', 255)->nullable()->after('reclassement_compte_origine_id');
            $table->timestamp('reclassee_at')->nullable()->after('reclassement_motif');
            $table->foreignId('reclassee_par_user_id')->nullable()->after('reclassee_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reclassement_compte_origine_id');
            $table->dropConstrainedForeignId('reclassee_par_user_id');
            $table->dropColumn(['reclassement_motif', 'reclassee_at']);
        });
    }
};
