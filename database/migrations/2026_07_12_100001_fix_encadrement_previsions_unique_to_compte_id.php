<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DC-10b-2 : le trait SyncCompteDepuisSousCategorie est supprimé —
 * sous_categorie_id n'est plus rempli sur les nouvelles lignes. L'index
 * unique existant (operation_id, tiers_id, sous_categorie_id, seance_id)
 * ne protège plus contre les doublons (NULL != NULL en SQL). On le
 * remplace par un index sur compte_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encadrement_previsions', function (Blueprint $table): void {
            $table->dropUnique('encadrement_previsions_unique');
            $table->unique(
                ['operation_id', 'tiers_id', 'compte_id', 'seance_id'],
                'encadrement_previsions_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('encadrement_previsions', function (Blueprint $table): void {
            $table->dropUnique('encadrement_previsions_unique');
            $table->unique(
                ['operation_id', 'tiers_id', 'sous_categorie_id', 'seance_id'],
                'encadrement_previsions_unique'
            );
        });
    }
};
