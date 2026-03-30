<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->foreignId('medecin_tiers_id')->nullable()->after('rgpd_accepte_at')->constrained('tiers')->nullOnDelete();
            $table->foreignId('therapeute_tiers_id')->nullable()->after('medecin_tiers_id')->constrained('tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('medecin_tiers_id');
            $table->dropConstrainedForeignId('therapeute_tiers_id');
        });
    }
};
