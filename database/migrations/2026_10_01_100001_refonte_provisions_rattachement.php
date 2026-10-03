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
     * purger : `transactions.provision_id` étant en `nullOnDelete`, un `delete`
     * laisserait les écritures OD de l'ancien schéma orphelines et
     * inatteignables (ProvisionPDService::supprimer() est indexé sur provision_id).
     *
     * La colonne `deleted_at` disparaît elle aussi : le retrait d'un rattachement
     * est physique (spec § 6.4) et le modèle n'a plus de suppression logique. Cette
     * migration n'ayant jamais été déployée, la modifier ne réécrit aucun historique.
     *
     * Le message d'erreur est écrit pour quelqu'un qui le découvre en plein
     * déploiement ou en plein retour arrière, et sa fin diffère selon le sens.
     * À la montée, la version déployée est celle de l'ancien écran : l'écran du lot
     * rattachement n'existe pas encore, le message donne les requêtes à passer et,
     * tant que la colonne existe, distingue les lignes actives des lignes supprimées
     * logiquement. Au retour arrière, le code du lot est encore déployé (sans lui ce
     * fichier n'existerait pas) : l'écran « Exercices > Écritures de rattachement »
     * est là, et c'est par lui qu'on retire un rattachement.
     */
    public function up(): void
    {
        $this->exigerTableVide(auRetourArriere: false);

        Schema::table('provisions', function (Blueprint $table) {
            $table->foreignId('transaction_ligne_id')
                ->nullable()
                ->after('exercice')
                ->constrained('transaction_lignes')
                ->restrictOnDelete();
        });

        Schema::table('provisions', function (Blueprint $table) {
            $table->dropColumn(['type', 'date']);
            $table->dropSoftDeletes();
            $table->string('libelle', 255)->nullable()->change();
        });
    }

    /**
     * Même garde qu'à la montée, mais la sortie diffère : ici le code du lot est
     * encore déployé et son écran retire un rattachement avec ses deux écritures ;
     * à la montée, cet écran n'existe pas encore et la table ne contient que des
     * provisions de l'ancien écran.
     */
    public function down(): void
    {
        $this->exigerTableVide(auRetourArriere: true);

        Schema::table('provisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_ligne_id');
            $table->string('type', 10)->default('recette');
            $table->date('date')->nullable();
            $table->string('libelle', 255)->nullable(false)->change();
            $table->softDeletes();
        });
    }

    /**
     * Refuse de continuer tant que la table contient une ligne, dans les deux sens.
     *
     * La colonne `deleted_at` n'existe que dans l'ancien schéma : à la montée sur
     * l'ancien schéma, les lignes actives et les lignes supprimées logiquement sont
     * comptées à part ; au retour arrière (la colonne a disparu), toute ligne est
     * active, il n'y a rien à distinguer — et rien que le message puisse proposer
     * de purger, la requête de purge visant une colonne absente.
     *
     * Les lignes supprimées logiquement sont comptées elles aussi : l'ancien écran
     * supprimait d'abord les écritures OD d'un rattachement (forceDelete), puis le
     * rattachement en suppression logique ; il ne reste donc normalement rien qui
     * s'y rattache, mais seul l'opérateur peut le constater, d'où la requête de
     * vérification.
     *
     * La fin du message est propre à chaque sens (voir le docblock de la classe) :
     * au retour arrière, chaque rattachement porte deux écritures, donc la requête
     * ne sert qu'à les compter et la sortie est l'écran de rattachement.
     */
    private function exigerTableVide(bool $auRetourArriere): void
    {
        $sens = $auRetourArriere ? 'au retour arrière' : 'à la montée';

        if (Schema::hasColumn('provisions', 'deleted_at')) {
            $actives = DB::table('provisions')->whereNull('deleted_at')->count();
            $supprimees = DB::table('provisions')->whereNotNull('deleted_at')->count();
            $detail = "{$actives} ligne(s) active(s), {$supprimees} ligne(s) supprimée(s) logiquement";
        } else {
            $actives = DB::table('provisions')->count();
            $supprimees = 0;
            $detail = "{$actives} ligne(s) (la table n'a plus de suppression logique : toutes sont actives)";
        }

        if ($actives + $supprimees === 0) {
            return;
        }

        $message = "Migration refusée {$sens} : la table provisions n'est pas vide — {$detail}. "
            .'Le lot rattachement réécrit cette table et suppose qu\'elle est vide. ';

        if ($auRetourArriere) {
            $message .= 'Comptage des écritures comptables liées à un rattachement : '
                .'SELECT COUNT(*) FROM transactions WHERE provision_id IS NOT NULL; '
                .'Des rattachements existent, chacun avec ses deux écritures (la requête ci-dessus les compte) : '
                .'retirez-les depuis l\'écran Exercices > Écritures de rattachement, qui supprime aussi leurs écritures, '
                .'puis relancez le retour arrière. Ne les supprimez pas à la main.';

            throw new RuntimeException($message);
        }

        $message .= 'Vérifiez d\'abord qu\'aucune écriture comptable n\'en dépend : '
            .'SELECT COUNT(*) FROM transactions WHERE provision_id IS NOT NULL; ';

        $message .= $actives > 0
            ? 'Des provisions de l\'ancien écran existent : ne les supprimez pas à la main '
                .'(leurs écritures OD resteraient orphelines) ; n\'allez pas plus loin '
                .'et examinez-les avec leurs écritures.'
            : 'Il ne reste que des lignes supprimées logiquement. Si ce comptage vaut 0, purgez-les avec : '
                .'DELETE FROM provisions WHERE deleted_at IS NOT NULL; puis relancez la migration.';

        throw new RuntimeException($message);
    }
};
