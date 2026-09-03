<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association_user', function (Blueprint $table): void {
            // Exercice choisi par cet utilisateur pour cette association : mémorisé
            // par couple, un utilisateur peut suivre 2025-2026 chez une association
            // et 2026-2027 chez une autre. Nullable : aucune préférence enregistrée
            // tant que l'utilisateur n'a jamais basculé explicitement.
            $table->unsignedSmallInteger('exercice_actif')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('association_user', function (Blueprint $table): void {
            $table->dropColumn('exercice_actif');
        });
    }
};
