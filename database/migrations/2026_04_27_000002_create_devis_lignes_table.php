<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devis_lignes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('devis_id');
            $table->foreign('devis_id')->references('id')->on('devis')->cascadeOnDelete();

            $table->integer('ordre')->default(1);
            $table->string('libelle');
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('quantite', 10, 3)->default(1);

            // Montant dénormalisé = prix_unitaire × quantite
            $table->decimal('montant', 12, 2);

            $table->unsignedBigInteger('sous_categorie_id')->nullable();
            $table->foreign('sous_categorie_id')->references('id')->on('sous_categories')->nullOnDelete();

            // Pas de timestamps — dénis_lignes sont des lignes de document, pas d'entités
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devis_lignes');
    }
};
