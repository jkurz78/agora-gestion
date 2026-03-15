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
        // Safety net: link any unlinked cotisations
        DB::table('cotisations')->whereNull('tiers_id')->update(['tiers_id' => DB::raw('membre_id')]);

        Schema::table('cotisations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('membre_id');
        });
    }

    public function down(): void
    {
        Schema::table('cotisations', function (Blueprint $table): void {
            $table->foreignId('membre_id')->nullable()->constrained('membres')->nullOnDelete();
        });
    }
};
