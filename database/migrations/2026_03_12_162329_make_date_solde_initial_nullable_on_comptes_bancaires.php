<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->date('date_solde_initial')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->date('date_solde_initial')->nullable(false)->change();
        });
    }
};
