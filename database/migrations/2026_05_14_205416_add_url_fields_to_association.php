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
        Schema::table('association', function (Blueprint $table) {
            $table->string('url_site_web', 255)->nullable();
            $table->string('url_renouvellement_adhesion', 255)->nullable();
            $table->string('url_nouveau_don', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn(['url_site_web', 'url_renouvellement_adhesion', 'url_nouveau_don']);
        });
    }
};
