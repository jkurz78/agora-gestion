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
            $table->date('date_adhesion')->nullable()->after('adresse');
            $table->string('statut_membre')->nullable()->after('date_adhesion');
            $table->text('notes_membre')->nullable()->after('statut_membre');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table): void {
            $table->dropColumn(['date_adhesion', 'statut_membre', 'notes_membre']);
        });
    }
};
