<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `adhesions_unique_per_exercice (association_id, tiers_id, exercice)` bloquait la
     * règle qu'il protégeait.
     *
     * L'exercice nul servait d'échappatoire aux adhésions en durée : en SQL, deux NULL
     * sont distincts. Renseigner l'exercice sur tous les modes (complétude) rend donc
     * impossibles deux adhésions d'un même tiers sur une même saison — une formule
     * trimestrielle renouvelée. Pire, l'index compte les lignes en suppression
     * logique : une adhésion annulée bloque la reprise d'une adhésion vivante.
     *
     * Nouvelle clé : (association_id, tiers_id, exercice, date_debut, adhesion_key).
     *  - `date_debut` autorise deux adhésions en durée de débuts différents. Pour une
     *    adhésion « par exercice », le début est celui de l'exercice : la protection
     *    « une par exercice » demeure.
     *  - `adhesion_key` vaut 1 pour une ligne vivante et NULL pour une ligne supprimée.
     *    Les lignes vivantes partagent la valeur et restent contraintes entre elles ;
     *    une ligne supprimée porte un NULL, distinct de tout, et ne bloque personne.
     *    Même motif que budget_lines.operation_key.
     *
     * VIRTUAL et non STORED : SQLite refuse d'ajouter une colonne générée STORED par
     * ALTER TABLE. L'expression est du SQL standard (CASE WHEN), sans fonction de date
     * propre à un moteur : DATE_FORMAT n'existe pas en SQLite, et sa valeur sur un
     * TIMESTAMP dépend du fuseau de session.
     *
     * ORDRE IMPÉRATIF : créer le nouvel index AVANT de supprimer l'ancien. L'ancien
     * sert de support à la clé étrangère `association_id` ; l'ordre inverse produit
     * l'erreur MySQL 1553. Le nouvel index, plus faible, est satisfait par toute donnée
     * existante : aucune vérification préalable n'est nécessaire.
     */
    public function up(): void
    {
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('adhesion_key')
                ->virtualAs('CASE WHEN deleted_at IS NULL THEN 1 END');
        });

        Schema::table('adhesions', function (Blueprint $table): void {
            $table->unique(
                ['association_id', 'tiers_id', 'exercice', 'date_debut', 'adhesion_key'],
                'adhesions_unique_exercice_debut'
            );
        });

        Schema::table('adhesions', function (Blueprint $table): void {
            $table->dropUnique('adhesions_unique_per_exercice');
        });
    }

    public function down(): void
    {
        // Ordre symétrique : l'ancien index d'abord (support de la clé étrangère).
        // Échoue, volontairement, si des adhésions d'un même tiers partagent un exercice.
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->unique(['association_id', 'tiers_id', 'exercice'], 'adhesions_unique_per_exercice');
        });

        Schema::table('adhesions', function (Blueprint $table): void {
            $table->dropUnique('adhesions_unique_exercice_debut');
        });

        Schema::table('adhesions', function (Blueprint $table): void {
            $table->dropColumn('adhesion_key');
        });
    }
};
