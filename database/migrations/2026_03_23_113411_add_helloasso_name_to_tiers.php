<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->string('helloasso_nom')->nullable()->after('est_helloasso');
            $table->string('helloasso_prenom')->nullable()->after('helloasso_nom');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropColumn(['helloasso_nom', 'helloasso_prenom']);
        });
    }
};
