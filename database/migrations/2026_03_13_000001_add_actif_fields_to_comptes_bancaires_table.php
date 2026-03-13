<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->boolean('actif_recettes_depenses')->default(true)->after('date_solde_initial');
            $table->boolean('actif_dons_cotisations')->default(true)->after('actif_recettes_depenses');
        });
    }

    public function down(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->dropColumn(['actif_recettes_depenses', 'actif_dons_cotisations']);
        });
    }
};
