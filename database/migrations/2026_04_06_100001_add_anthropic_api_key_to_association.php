<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->text('anthropic_api_key')->nullable()->after('facture_compte_bancaire_id');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn('anthropic_api_key');
        });
    }
};
