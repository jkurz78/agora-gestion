<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->date('date_reglement')->nullable()->after('reglement_id');
            $table->string('reference_reglement')->nullable()->after('date_reglement');
            $table->foreignId('compte_origine_id')->nullable()->after('reference_reglement')
                ->constrained('comptes_bancaires');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['compte_origine_id']);
            $table->dropColumn(['date_reglement', 'reference_reglement', 'compte_origine_id']);
        });
    }
};
