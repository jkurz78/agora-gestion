<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table): void {
            $table->dropColumn(['statut_membre', 'date_adhesion', 'notes_membre']);
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table): void {
            $table->string('statut_membre')->nullable();
            $table->date('date_adhesion')->nullable();
            $table->text('notes_membre')->nullable();
        });
    }
};
