<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devis', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('association_id');
            $table->foreign('association_id')->references('id')->on('association')->cascadeOnDelete();

            // Numéro de référence (D-{exercice}-NNN), NULL jusqu'à la transition → envoyé
            $table->string('numero')->nullable();

            $table->unsignedBigInteger('tiers_id');
            $table->foreign('tiers_id')->references('id')->on('tiers')->restrictOnDelete();

            $table->date('date_emission');
            $table->date('date_validite');
            $table->string('libelle')->nullable();

            // Enum géré applicativement via App\Enums\StatutDevis
            $table->string('statut')->default('brouillon');

            $table->decimal('montant_total', 12, 2)->default(0);

            // Exercice comptable figé à la création (déduit de date_emission)
            $table->integer('exercice');

            // Traces de transitions
            $table->unsignedBigInteger('accepte_par_user_id')->nullable();
            $table->foreign('accepte_par_user_id')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('accepte_le')->nullable();

            $table->unsignedBigInteger('refuse_par_user_id')->nullable();
            $table->foreign('refuse_par_user_id')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('refuse_le')->nullable();

            $table->unsignedBigInteger('annule_par_user_id')->nullable();
            $table->foreign('annule_par_user_id')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('annule_le')->nullable();

            $table->unsignedBigInteger('saisi_par_user_id')->nullable();
            $table->foreign('saisi_par_user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Index de performance
            $table->index(['association_id', 'statut']);
            $table->index(['association_id', 'tiers_id']);

            /*
             * Unicité (association_id, exercice, numero) pour les numéros attribués.
             *
             * Choix MySQL : index unique composite sur les 3 colonnes.
             * Sur MySQL, une valeur NULL dans une colonne d'un index UNIQUE n'est pas
             * considérée comme égale à une autre NULL — deux lignes peuvent donc avoir
             * numero = NULL sans violer la contrainte. On n'a donc pas besoin de partial
             * index (syntaxe PostgreSQL uniquement) : l'unicité est naturellement
             * garantie uniquement quand numero IS NOT NULL.
             */
            $table->unique(['association_id', 'exercice', 'numero'], 'devis_asso_exercice_numero_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devis');
    }
};
