<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->integer('devis_validite_jours')->default(30)->after('wizard_current_step');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn('devis_validite_jours');
        });
    }
};
