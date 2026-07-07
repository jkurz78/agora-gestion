<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\VentilationCompteIdBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DC-2 du programme « dissolution sous_categories → comptes ».
 *
 * Ajoute `compte_id` (FK nullable vers `comptes`) sur les 10 tables de
 * ventilation qui ne portaient jusqu'ici que `sous_categorie_id`
 * (`transaction_lignes` a déjà `compte_id` depuis la sous-slice 1d — hors
 * périmètre ici).
 *
 * Le backfill (mapping `sous_categories.code_cerfa = comptes.numero_pcg`,
 * même `association_id`) est délégué à `VentilationCompteIdBackfiller`
 * pour pouvoir être rejoué en test sans dérouler la migration complète —
 * même pattern que `CompteIdBackfiller` (Step 36).
 *
 * `sous_categorie_id` n'est pas retiré : la dissolution complète (drop de
 * la colonne legacy) est un chantier ultérieur, une fois compte_id vérifié
 * en prod sur toutes les tables.
 */
return new class extends Migration
{
    /**
     * Les 10 tables de ventilation concernées par ce DC-2.
     */
    private const TABLES = [
        'budget_lines',
        'facture_lignes',
        'devis_lignes',
        'formules_adhesion',
        'type_operations',
        'helloasso_form_mappings',
        'provisions',
        'usages_sous_categories',
        'notes_de_frais_lignes',
        'encadrement_previsions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('compte_id')->nullable()->after('sous_categorie_id')
                    ->constrained('comptes')->nullOnDelete();
            });
        }

        $resultats = VentilationCompteIdBackfiller::backfill();

        $total = array_sum(array_diff_key($resultats, ['non_mappees' => true]));
        echo "  [DC-2] Backfill compte_id : {$total} ligne(s) mise(s) à jour, ".
            "{$resultats['non_mappees']} sous-catégorie(s) non mappée(s).\n";
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('compte_id');
            });
        }
    }
};
