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
        Schema::table('campagnes_email', function (Blueprint $table) {
            $table->dropForeign(['operation_id']);
            $table->unsignedBigInteger('operation_id')->nullable()->change();
            $table->foreign('operation_id')
                ->references('id')
                ->on('operations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Delete orphaned rows first
        DB::table('campagnes_email')
            ->whereNull('operation_id')
            ->delete();

        Schema::table('campagnes_email', function (Blueprint $table) {
            $table->dropForeign(['operation_id']);
            $table->unsignedBigInteger('operation_id')->nullable(false)->change();
            $table->foreign('operation_id')
                ->references('id')
                ->on('operations')
                ->cascadeOnDelete();
        });
    }
};
