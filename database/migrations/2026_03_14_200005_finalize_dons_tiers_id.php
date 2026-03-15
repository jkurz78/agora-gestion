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
        // Safety net: set tiers_id for any dons that still have donateur_id but no tiers_id
        DB::table('dons')->whereNull('tiers_id')->update(['tiers_id' => DB::raw('donateur_id')]);

        Schema::table('dons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('donateur_id');
        });
    }

    public function down(): void
    {
        Schema::table('dons', function (Blueprint $table): void {
            $table->foreignId('donateur_id')->nullable()->constrained('donateurs')->nullOnDelete();
        });
    }
};
