<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisions', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('exercice')->index();
            $table->string('type', 10); // 'depense' or 'recette'
            $table->foreignId('sous_categorie_id')->constrained('sous_categories');
            $table->string('libelle', 255);
            $table->decimal('montant', 10, 2);
            $table->foreignId('tiers_id')->nullable()->constrained('tiers');
            $table->foreignId('operation_id')->nullable()->constrained('operations');
            $table->integer('seance')->nullable();
            $table->date('date');
            $table->text('notes')->nullable();
            $table->string('piece_jointe_path')->nullable();
            $table->string('piece_jointe_nom')->nullable();
            $table->string('piece_jointe_mime')->nullable();
            $table->foreignId('saisi_par')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisions');
    }
};
