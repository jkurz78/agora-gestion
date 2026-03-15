<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recette_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recette_id')->constrained('recettes')->cascadeOnDelete();
            $table->foreignId('sous_categorie_id')->constrained('sous_categories');
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->integer('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->text('notes')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recette_lignes');
    }
};
