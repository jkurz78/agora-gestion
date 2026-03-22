<?php

// database/migrations/2026_03_22_100000_make_tiers_nom_nullable.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->string('nom', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->string('nom', 150)->change();
        });
    }
};
