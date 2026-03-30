<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_operations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table) {
            $table->string('code', 20)->unique()->after('id');
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->string('code', 50)->unique()->after('id');
        });
    }
};
