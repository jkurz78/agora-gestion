<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->boolean('pour_inscriptions')->default(false)->after('pour_cotisations');
        });
    }

    public function down(): void
    {
        Schema::table('sous_categories', function (Blueprint $table) {
            $table->dropColumn('pour_inscriptions');
        });
    }
};
