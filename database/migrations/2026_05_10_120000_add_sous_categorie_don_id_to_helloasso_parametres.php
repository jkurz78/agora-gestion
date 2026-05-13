<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table): void {
            $table->foreignId('sous_categorie_don_id')
                ->nullable()
                ->after('compte_versement_id')
                ->constrained('sous_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sous_categorie_don_id');
        });
    }
};
