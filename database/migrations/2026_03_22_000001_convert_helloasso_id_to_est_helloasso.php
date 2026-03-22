<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropUnique(['helloasso_id']);
            $table->dropColumn('helloasso_id');
        });

        Schema::table('tiers', function (Blueprint $table) {
            $table->boolean('est_helloasso')->default(false)->after('pour_recettes');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropColumn('est_helloasso');
        });

        Schema::table('tiers', function (Blueprint $table) {
            $table->string('helloasso_id', 255)->nullable()->unique()->after('date_naissance');
        });
    }
};
