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
        Schema::table('operations', function (Blueprint $table) {
            $table->string('code', 50)->after('id')->default('');
        });

        // Copy nom to code for existing operations
        DB::statement('UPDATE operations SET code = SUBSTRING(nom, 1, 50)');

        // Now make it unique and remove default
        Schema::table('operations', function (Blueprint $table) {
            $table->string('code', 50)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
