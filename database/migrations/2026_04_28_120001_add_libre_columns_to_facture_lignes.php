<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facture_lignes', function (Blueprint $table) {
            // Colonnes propres aux lignes MontantLibre — toutes nullables pour
            // ne pas casser les lignes Montant (ref) et Texte existantes.

            // Prix unitaire : decimal(12,2), positif strict côté service
            $table->decimal('prix_unitaire', 12, 2)
                ->nullable()
                ->after('montant');

            // Quantité : decimal(10,3) pour gérer les fractions (heures, jours…)
            $table->decimal('quantite', 10, 3)
                ->nullable()
                ->after('prix_unitaire');

            // Sous-catégorie comptable — requise à la validation si MontantLibre
            $table->foreignId('sous_categorie_id')
                ->nullable()
                ->after('quantite')
                ->constrained('sous_categories')
                ->nullOnDelete();

            // Opération et séance — optionnels, information de contexte
            $table->foreignId('operation_id')
                ->nullable()
                ->after('sous_categorie_id')
                ->constrained('operations')
                ->nullOnDelete();

            $table->integer('seance')
                ->nullable()
                ->after('operation_id');
        });
    }

    public function down(): void
    {
        Schema::table('facture_lignes', function (Blueprint $table) {
            $table->dropForeign(['sous_categorie_id']);
            $table->dropForeign(['operation_id']);
            $table->dropColumn([
                'prix_unitaire',
                'quantite',
                'sous_categorie_id',
                'operation_id',
                'seance',
            ]);
        });
    }
};
