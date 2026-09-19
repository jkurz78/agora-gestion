<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La colonne « Kiné » de l'onglet Séances s'affichait pour les types en
     * parcours thérapeutique. Elle est désormais pilotée par l'option de
     * participation : on l'active avec le libellé historique pour que rien ne
     * change à la livraison. Un type jamais réglé a un libellé null ; un type
     * déjà réglé (libellé renseigné) n'est pas touché — la reprise est
     * rejouable. Requête brute : reprise technique, toutes associations.
     */
    public function up(): void
    {
        DB::table('type_operations')
            ->where('formulaire_parcours_therapeutique', true)
            ->where('participation_seance_active', false)
            ->whereNull('participation_seance_libelle')
            ->update([
                'participation_seance_active' => true,
                'participation_seance_libelle' => 'Kiné',
            ]);
    }

    public function down(): void
    {
        // Les colonnes disparaissent avec le rollback de la migration de schéma.
    }
};
