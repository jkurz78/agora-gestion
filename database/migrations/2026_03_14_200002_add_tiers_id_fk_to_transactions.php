<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dons', function (Blueprint $table): void {
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete()->after('donateur_id');
        });

        Schema::table('cotisations', function (Blueprint $table): void {
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete()->after('membre_id');
        });

        Schema::table('depenses', function (Blueprint $table): void {
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete()->after('mode_paiement');
        });

        Schema::table('recettes', function (Blueprint $table): void {
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete()->after('mode_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('dons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tiers_id');
        });
        Schema::table('cotisations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tiers_id');
        });
        Schema::table('depenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tiers_id');
        });
        Schema::table('recettes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tiers_id');
        });
    }
};
