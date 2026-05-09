<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formules_adhesion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained('association')->cascadeOnDelete();
            $table->string('nom', 120);
            $table->text('description')->nullable();
            $table->enum('mode', ['exercice', 'duree']);
            $table->unsignedSmallInteger('duree_mois')->nullable();
            $table->decimal('montant_par_defaut', 10, 2)->nullable();
            $table->boolean('deductible_fiscal')->default(false);
            $table->foreignId('sous_categorie_id')->constrained('sous_categories')->cascadeOnDelete();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['association_id', 'actif'], 'formules_adhesion_actif_idx');
            $table->index('sous_categorie_id', 'formules_adhesion_souscat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formules_adhesion');
    }
};
