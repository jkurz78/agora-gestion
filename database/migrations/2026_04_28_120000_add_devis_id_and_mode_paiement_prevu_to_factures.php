<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            // FK nullable vers devis — ON DELETE RESTRICT (le devis ne peut pas
            // être supprimé tant qu'une facture y est attachée)
            $table->foreignId('devis_id')
                ->nullable()
                ->after('association_id')
                ->constrained('devis')
                ->restrictOnDelete();

            // Mode de règlement prévisionnel (cast en enum ModePaiement côté modèle)
            $table->string('mode_paiement_prevu')
                ->nullable()
                ->after('conditions_reglement');

            // Index composite pour la lookup "facture issue de ce devis"
            $table->index(['association_id', 'devis_id'], 'factures_asso_devis_idx');
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropIndex('factures_asso_devis_idx');
            $table->dropForeign(['devis_id']);
            $table->dropColumn(['devis_id', 'mode_paiement_prevu']);
        });
    }
};
