<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recette_ligne_affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recette_ligne_id')->constrained('recette_lignes')->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->unsignedInteger('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recette_ligne_affectations');
    }
};
