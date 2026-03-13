<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recettes', function (Blueprint $table) {
            $table->renameColumn('payeur', 'tiers');
        });
    }

    public function down(): void
    {
        Schema::table('recettes', function (Blueprint $table) {
            $table->renameColumn('tiers', 'payeur');
        });
    }
};
