<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute motif_suppression et supprime_par à la table transactions.
 *
 * Permet de distinguer une suppression technique (correction de saisie)
 * d'une annulation métier (chèque impayé, erreur comptable, etc.)
 * tout en réutilisant le SoftDeletes existant (deleted_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->text('motif_suppression')->nullable()->after('deleted_at');
            $table->foreignId('supprime_par')->nullable()->after('motif_suppression')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['supprime_par']);
            $table->dropColumn(['motif_suppression', 'supprime_par']);
        });
    }
};
