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
            // Drop FK first if present, then column
            try {
                $table->dropForeign(['sous_categorie_don_id']);
            } catch (Throwable $e) {
            }
            try {
                $table->dropForeign(['sous_categorie_cotisation_id']);
            } catch (Throwable $e) {
            }
            try {
                $table->dropForeign(['sous_categorie_inscription_id']);
            } catch (Throwable $e) {
            }
            $table->dropColumn(['sous_categorie_don_id', 'sous_categorie_cotisation_id', 'sous_categorie_inscription_id']);
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_parametres', function (Blueprint $table): void {
            $table->foreignId('sous_categorie_don_id')->nullable()->constrained('sous_categories')->nullOnDelete();
            $table->foreignId('sous_categorie_cotisation_id')->nullable()->constrained('sous_categories')->nullOnDelete();
            $table->foreignId('sous_categorie_inscription_id')->nullable()->constrained('sous_categories')->nullOnDelete();
        });
    }
};
