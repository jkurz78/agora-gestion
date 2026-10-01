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
     */
    public function up(): void
    {
        $this->exigerTableVide();

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
        $this->exigerTableVide();

        Schema::table('provisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_ligne_id');
            $table->string('type', 10)->default('recette');
            $table->date('date')->nullable();
            $table->string('libelle', 255)->nullable(false)->change();
        });
    }

    /**
     * Compte aussi les lignes supprimées logiquement : elles portent des
     * écritures et ne doivent pas disparaître en silence.
     */
    private function exigerTableVide(): void
    {
        $restantes = DB::table('provisions')->count();

        if ($restantes > 0) {
            throw new RuntimeException(
                "Migration refusée : la table provisions contient {$restantes} ligne(s). "
                .'Le lot rattachement réécrit cette table et suppose qu\'elle est vide. '
                .'Retirez ces rattachements par l\'IHM — ce qui supprime aussi leurs écritures — avant de migrer.'
            );
        }
    }
};
