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
     *  - `date_debut` autorise deux adhésions en durée de débuts différents. Elle refuse
     *    en revanche deux adhésions vivantes d'un même tiers qui commencent le même jour
     *    d'une même saison, quelle que soit la formule : c'est la règle « une adhésion
     *    par tiers et par saison » pour le cas où elles se recouvrent exactement.
     *  - `adhesion_key` vaut 1 pour une ligne vivante et NULL pour une ligne supprimée.
     *    Les lignes vivantes partagent la valeur et restent contraintes entre elles ;
     *    une ligne supprimée porte un NULL, distinct de tout, et ne bloque personne.
     *    Même motif que budget_lines.operation_key.
     *
     * LIMITE : une ligne dont `exercice` ou `date_debut` est NULL n'est contrainte par
     * rien — un NULL neutralise un index unique. C'est le cas des adhésions offertes de
     * creerGratuite() (mode et date_debut nuls). Leur protection reste applicative.
     *
     * VIRTUAL et non STORED : SQLite refuse d'ajouter une colonne générée STORED par
     * ALTER TABLE.
     *
     * L'expression est du SQL standard (CASE WHEN), sans fonction de date. La raison
     * décisive : MariaDB 11.4, le moteur de production, REFUSE DATE_FORMAT dans une
     * clause GENERATED ALWAYS AS — « ERROR 1901 (HY000): Function or expression
     * 'date_format()' cannot be used in the GENERATED ALWAYS AS clause » — et
     * artisan migrate y aurait échoué au déploiement. Par ailleurs MySQL l'accepte,
     * SQLite n'a pas la fonction, sa valeur sur un TIMESTAMP dépend du fuseau de session
     * et deux lignes supprimées dans la même seconde auraient collisionné.
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
        //
        // ⚠️ IRRÉVERSIBLE APRÈS LA REPRISE. Ce retour arrière échoue en 1062 dès que deux
        // lignes d'un même tiers partagent un exercice — y compris une ligne supprimée
        // logiquement, que l'ancien index comptait. C'est le cas de Notz : la ligne 25
        // (reprise à l'exercice 2025) et la ligne 26, « Adhésion legacy » supprimée, qui
        // porte aussi 2025. Revenir en arrière passe par une restauration de la base.
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
