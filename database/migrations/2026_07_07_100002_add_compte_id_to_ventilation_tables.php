<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DC-2 du programme « dissolution sous_categories → comptes ».
 *
 * Ajoute `compte_id` (FK nullable vers `comptes`) sur les 10 tables de
 * ventilation qui ne portaient jusqu'ici que `sous_categorie_id`.
 *
 * Le backfill (mapping `sous_categories.code_cerfa = comptes.numero_pcg`,
 * même `association_id`) est inliné ci-dessous.
 */
return new class extends Migration
{
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

        $this->backfill();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('compte_id');
            });
        }
    }

    private function backfill(): void
    {
        $sousCategories = DB::table('sous_categories')->select(['id', 'association_id', 'code_cerfa'])->get();

        $comptesParCle = [];
        foreach (DB::table('comptes')->select(['id', 'association_id', 'numero_pcg'])->get() as $compte) {
            $cle = ((int) $compte->association_id).':'.$compte->numero_pcg;
            $comptesParCle[$cle] ??= (int) $compte->id;
        }

        $mapping = [];
        $nonMappees = 0;

        foreach ($sousCategories as $sc) {
            if ($sc->code_cerfa === null) {
                $nonMappees++;

                continue;
            }
            $cle = ((int) $sc->association_id).':'.$sc->code_cerfa;
            if (! isset($comptesParCle[$cle])) {
                $nonMappees++;

                continue;
            }
            $mapping[(int) $sc->id] = $comptesParCle[$cle];
        }

        $total = 0;
        foreach (self::TABLES as $table) {
            foreach ($mapping as $sousCategorieId => $compteId) {
                $total += DB::table($table)
                    ->where('sous_categorie_id', $sousCategorieId)
                    ->whereNull('compte_id')
                    ->update(['compte_id' => $compteId]);
            }
        }

        echo "  [DC-2] Backfill compte_id : {$total} ligne(s) mise(s) à jour, {$nonMappees} sous-catégorie(s) non mappée(s).\n";
    }
};
