<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reglements', function (Blueprint $table) {
            $table->foreign('remise_id')->references('id')->on('remises_bancaires')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reglements', function (Blueprint $table) {
            $table->dropForeign(['remise_id']);
        });
    }
};
