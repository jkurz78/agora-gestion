<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit robustesse — point 2 : rien n'empêchait aujourd'hui deux fiches (ou
 * deux dotations) de pointer la même transaction. On rend l'unicité
 * structurelle plutôt que purement conventionnelle.
 *
 * Nuance à ne pas perdre : `immobilisations.transaction_id` traduit
 * l'invariant d'AUJOURD'HUI, où une fiche naît d'un unique achat. La
 * conception anticipe explicitement le cas contraire —
 * App\Models\Immobilisation::transactionsAcquisition() est délibérément au
 * pluriel et retourne une Collection, précisément pour que le jour où une
 * immobilisation sera constituée de plusieurs achats (FK 1:1 → table pivot
 * N:N), aucun site de lecture n'ait à changer. Ce jour-là, cet index unique
 * devra être levé (ou déplacé sur la future table pivot) : ne pas le
 * reconduire aveuglément en pensant « c'est toujours comme ça ».
 *
 * SoftDeletes — `immobilisations` en porte, `immobilisation_dotations` non :
 *   - immobilisations : une fiche supprimée (et sa transaction, purgée avec
 *     elle par ImmobilisationService::supprimer()) ne bloque jamais la
 *     création d'une nouvelle fiche, parce que celle-ci reçoit toujours une
 *     transaction fraîche (nouvel ID auto-incrémenté, jamais réutilisé) —
 *     l'unicité plate (sans deleted_at) ne peut donc pas collisionner avec
 *     une ligne déjà soft-deleted. Voir
 *     UniciteTransactionIdTest::"une fiche supprimée puis une nouvelle
 *     création ne se heurtent pas à l'index unique".
 *   - immobilisation_dotations : pas de SoftDeletes, DotationService::annuler()
 *     fait un vrai delete() — aucune ligne fantôme ne peut jamais entrer en
 *     collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('immobilisations', function (Blueprint $table): void {
            $table->dropIndex('immobilisations_transaction_id_index');
            $table->unique('transaction_id', 'immobilisations_transaction_id_unique');
        });

        Schema::table('immobilisation_dotations', function (Blueprint $table): void {
            $table->dropIndex('immobilisation_dotations_transaction_id_index');
            $table->unique('transaction_id', 'immobilisation_dotations_transaction_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('immobilisations', function (Blueprint $table): void {
            $table->dropUnique('immobilisations_transaction_id_unique');
            $table->index('transaction_id', 'immobilisations_transaction_id_index');
        });

        Schema::table('immobilisation_dotations', function (Blueprint $table): void {
            $table->dropUnique('immobilisation_dotations_transaction_id_unique');
            $table->index('transaction_id', 'immobilisation_dotations_transaction_id_index');
        });
    }
};
