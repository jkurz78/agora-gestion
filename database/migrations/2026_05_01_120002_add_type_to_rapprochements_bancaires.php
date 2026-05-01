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
        Schema::table('rapprochements_bancaires', function (Blueprint $table): void {
            $table->string('type', 20)->default('bancaire')->after('statut');
            $table->index('type');
        });

        // Backfill : explicit set of all existing rows to 'bancaire' (the default
        // already covers it but we ensure idempotence and clarity for any row
        // that might have been inserted with NULL via legacy paths).
        DB::table('rapprochements_bancaires')->update(['type' => 'bancaire']);
    }

    public function down(): void
    {
        Schema::table('rapprochements_bancaires', function (Blueprint $table): void {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
