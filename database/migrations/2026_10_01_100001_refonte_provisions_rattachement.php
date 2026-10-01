<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réécriture de la table provisions : elle est vide sur toutes les
     * associations de production, aucune reprise de données n'est nécessaire.
     *
     * La migration REFUSE de s'exécuter sur une table non vide plutôt que de la
     * purger : un `delete` contournerait les SoftDeletes d'une table financière,
     * et `transactions.provision_id` étant en `nullOnDelete`, il laisserait les
     * écritures OD de l'ancien schéma orphelines et inatteignables
     * (ProvisionPDService::supprimer() est indexé sur provision_id).
     *
     * Le message d'erreur est écrit pour quelqu'un qui le découvre en plein
     * déploiement : il distingue les lignes actives des lignes supprimées
     * logiquement et donne les requêtes à passer, sans compter sur un écran
     * (celui du lot rattachement n'existe pas encore sur la version déployée).
     */
    public function up(): void
    {
        $this->exigerTableVide('à la montée');

        Schema::table('provisions', function (Blueprint $table) {
            $table->foreignId('transaction_ligne_id')
                ->nullable()
                ->after('exercice')
                ->constrained('transaction_lignes')
                ->restrictOnDelete();
        });

        Schema::table('provisions', function (Blueprint $table) {
            $table->dropColumn(['type', 'date']);
            $table->string('libelle', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->exigerTableVide('au retour arrière');

        Schema::table('provisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_ligne_id');
            $table->string('type', 10)->default('recette');
            $table->date('date')->nullable();
            $table->string('libelle', 255)->nullable(false)->change();
        });
    }

    /**
     * Compte séparément les lignes actives et les lignes supprimées logiquement.
     *
     * Les secondes sont comptées elles aussi : l'ancien écran supprimait d'abord
     * les écritures OD d'un rattachement (forceDelete), puis le rattachement en
     * suppression logique ; il ne reste donc normalement rien qui s'y rattache,
     * mais seul l'opérateur peut le constater, d'où la requête de vérification.
     */
    private function exigerTableVide(string $sens): void
    {
        $actives = DB::table('provisions')->whereNull('deleted_at')->count();
        $supprimees = DB::table('provisions')->whereNotNull('deleted_at')->count();

        if ($actives + $supprimees === 0) {
            return;
        }

        $message = "Migration refusée {$sens} : la table provisions n'est pas vide — "
            ."{$actives} ligne(s) active(s), {$supprimees} ligne(s) supprimée(s) logiquement. "
            .'Le lot rattachement réécrit cette table et suppose qu\'elle est vide. '
            .'Vérifiez d\'abord qu\'aucune écriture comptable n\'en dépend : '
            .'SELECT COUNT(*) FROM transactions WHERE provision_id IS NOT NULL; ';

        $message .= $actives > 0
            ? 'Des rattachements actifs existent : ne les supprimez pas à la main, arrêtez le déploiement '
                .'et examinez-les (et leurs écritures) avant toute décision.'
            : 'Il ne reste que des lignes supprimées logiquement. Si ce comptage vaut 0, purgez-les avec : '
                .'DELETE FROM provisions WHERE deleted_at IS NOT NULL; puis relancez la migration.';

        throw new RuntimeException($message);
    }
};
