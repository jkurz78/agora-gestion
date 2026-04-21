<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes_de_frais_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_de_frais_id')->constrained('notes_de_frais')->cascadeOnDelete();
            $table->foreignId('sous_categorie_id')->nullable()->constrained('sous_categories')->nullOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->foreignId('seance_id')->nullable()->constrained('seances')->nullOnDelete();
            $table->string('libelle')->nullable();
            $table->decimal('montant', 10, 2);
            $table->string('piece_jointe_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes_de_frais_lignes');
    }
};
