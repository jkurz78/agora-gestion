<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->foreignId('type_operation_id')->nullable()->after('statut')->constrained('type_operations');
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sous_categorie_id');
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->foreignId('sous_categorie_id')->nullable()->constrained('sous_categories');
        });

        Schema::table('operations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('type_operation_id');
        });
    }
};
