<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;

/**
 * DC-2 du programme « dissolution sous_categories → comptes ».
 *
 * Peuple `compte_id` sur les 10 tables de ventilation qui ne portaient
 * jusqu'ici que `sous_categorie_id` (`transaction_lignes` a été backfillée
 * à part, cf. `CompteIdBackfiller`, et reste hors périmètre ici).
 *
 * Mapping : `sous_categories.code_cerfa = comptes.numero_pcg`, apparié au
 * sein d'une même `association_id` (jamais de mapping cross-tenant).
 *
 * Implémentation en PHP + requêtes simples (pas de JOIN/UPDATE bruts) afin
 * de fonctionner identiquement sur MySQL et SQLite (tests) — même approche
 * que `FamillesSeeder` (DC-1).
 *
 * Idempotent : chaque UPDATE ne touche que les lignes `compte_id IS NULL`,
 * donc un second passage est un no-op pour les lignes déjà backfillées.
 *
 * Orphelins : une sous-catégorie sans `code_cerfa`, ou dont le `code_cerfa`
 * n'a pas de compte correspondant dans le même tenant, ne produit aucun
 * mapping — les lignes qui la référencent gardent `compte_id` à NULL. Le
 * compteur `non_mappees` retourné par `backfill()` permet de les détecter.
 */
final class VentilationCompteIdBackfiller
{
    /**
     * Les 10 tables de ventilation à backfiller (hors transaction_lignes,
     * déjà traitée par CompteIdBackfiller).
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

    /**
     * Exécute le backfill sur les 10 tables.
     *
     * @return array<string, int> nombre de lignes mises à jour par table,
     *                            plus la clé `non_mappees` (nb de sous-catégories
     *                            sans mapping vers un compte).
     */
    public static function backfill(): array
    {
        [$mapping, $nonMappees] = self::construireMapping();

        $resultats = [];

        foreach (self::TABLES as $table) {
            $resultats[$table] = self::backfillTable($table, $mapping);
        }

        $resultats['non_mappees'] = $nonMappees;

        return $resultats;
    }

    /**
     * Construit la correspondance sous_categorie_id → compte_id, une fois
     * pour toutes les tables, en appariant (association_id, code_cerfa) côté
     * sous-catégories avec (association_id, numero_pcg) côté comptes.
     *
     * @return array{0: array<int, int>, 1: int} [mapping sous_categorie_id => compte_id, nb non mappées]
     */
    private static function construireMapping(): array
    {
        $sousCategories = DB::table('sous_categories')->select(['id', 'association_id', 'code_cerfa'])->get();

        // Index des comptes par "association_id:numero_pcg" pour une résolution O(1).
        $comptesParCle = [];
        foreach (DB::table('comptes')->select(['id', 'association_id', 'numero_pcg'])->get() as $compte) {
            $cle = ((int) $compte->association_id).':'.$compte->numero_pcg;
            $comptesParCle[$cle] ??= (int) $compte->id;
        }

        $mapping = [];
        $nonMappees = 0;

        foreach ($sousCategories as $sousCategorie) {
            // Sous-catégorie sans code_cerfa : jamais mappable, comptée comme non mappée.
            if ($sousCategorie->code_cerfa === null) {
                $nonMappees++;

                continue;
            }

            $cle = ((int) $sousCategorie->association_id).':'.$sousCategorie->code_cerfa;

            if (! isset($comptesParCle[$cle])) {
                $nonMappees++;

                continue;
            }

            $mapping[(int) $sousCategorie->id] = $comptesParCle[$cle];
        }

        return [$mapping, $nonMappees];
    }

    /**
     * Applique le mapping à une table : pour chaque (sous_categorie_id => compte_id),
     * met à jour les lignes qui référencent cette sous-catégorie et n'ont pas
     * encore de compte_id.
     *
     * @param  array<int, int>  $mapping
     */
    private static function backfillTable(string $table, array $mapping): int
    {
        $affected = 0;

        foreach ($mapping as $sousCategorieId => $compteId) {
            $affected += DB::table($table)
                ->where('sous_categorie_id', $sousCategorieId)
                ->whereNull('compte_id')
                ->update(['compte_id' => $compteId]);
        }

        return $affected;
    }
}
