<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depense_ligne_affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depense_ligne_id')->constrained('depense_lignes')->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->unsignedInteger('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depense_ligne_affectations');
    }
};
