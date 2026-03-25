<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->text('taille')->nullable()->after('poids');
            $table->text('notes')->nullable()->after('taille');
        });
    }

    public function down(): void
    {
        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->dropColumn(['taille', 'notes']);
        });
    }
};
