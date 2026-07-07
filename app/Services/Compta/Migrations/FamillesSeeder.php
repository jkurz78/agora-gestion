<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DC-1 du programme « dissolution sous_categories → comptes ».
 *
 * Peuple `familles` à partir des données existantes :
 *
 *  1. Chaque `categories.nom` au format "NN - Libellé" devient une famille
 *     (code=NN, nom=Libellé trim). Idempotent par (association_id, code) —
 *     si deux catégories d'une même association parsent le même code, la
 *     première rencontrée gagne, les suivantes sont ignorées.
 *  2. Chaque préfixe distinct des `comptes` de classe 6/7 qui n'a toujours
 *     pas de famille reçoit une famille de secours (nom = code), pour ne
 *     jamais laisser un compte de résultat orphelin de famille.
 *
 * Implémentation en PHP + requêtes simples (pas de JOIN/UPDATE bruts) afin
 * de fonctionner identiquement sur MySQL et SQLite (tests). Extrait de la
 * migration pour permettre son rejeu direct dans les tests (mirror
 * AuditGuard/SystemeSeeder du même répertoire).
 */
final class FamillesSeeder
{
    /**
     * Regex du format legacy des catégories : "NN - Libellé" (tirets simples
     * ou multiples espaces tolérés autour du séparateur).
     */
    private const REGEX_CATEGORIE = '/^(\d{2})\s*-\s*(.+)$/u';

    public static function seed(): void
    {
        self::seedDepuisCategories();
        self::seedDepuisComptesOrphelins();
    }

    /**
     * Parse chaque `categories.nom` existant et matérialise une famille par
     * (association_id, code) — première occurrence gagnante.
     */
    private static function seedDepuisCategories(): void
    {
        $categories = DB::table('categories')->select(['association_id', 'nom'])->get();

        // Codes déjà vus dans CE passage, par association — pour appliquer la
        // règle "première catégorie gagne" avant même de toucher la BDD.
        $vusDansCePasse = [];

        foreach ($categories as $categorie) {
            if (! preg_match(self::REGEX_CATEGORIE, (string) $categorie->nom, $matches)) {
                continue;
            }

            $associationId = (int) $categorie->association_id;
            $code = $matches[1];
            $nom = trim($matches[2]);

            $cle = $associationId.':'.$code;

            if (isset($vusDansCePasse[$cle])) {
                continue;
            }

            $vusDansCePasse[$cle] = true;

            self::inserer($associationId, $code, $nom);
        }
    }

    /**
     * Pour chaque préfixe distinct des comptes de classe 6/7 sans famille
     * encore nommée, crée une famille de secours (nom = code).
     */
    private static function seedDepuisComptesOrphelins(): void
    {
        $comptes = DB::table('comptes')
            ->select(['association_id', 'numero_pcg'])
            ->whereIn('classe', [6, 7])
            ->get();

        $prefixesParAssociation = [];

        foreach ($comptes as $compte) {
            $associationId = (int) $compte->association_id;
            $code = Str::substr((string) $compte->numero_pcg, 0, 2);

            $prefixesParAssociation[$associationId][$code] = true;
        }

        foreach ($prefixesParAssociation as $associationId => $codes) {
            // array_keys() caste les clés numériques (ex. "70") en int — on force
            // la conversion string pour respecter la colonne `code` (varchar).
            foreach (array_keys($codes) as $code) {
                $code = (string) $code;
                self::inserer($associationId, $code, $code);
            }
        }
    }

    /**
     * Insère une famille si elle n'existe pas déjà pour (association_id, code).
     * INSERT IGNORE / INSERT OR IGNORE — idempotent, rejouable sans jamais
     * écraser un nom déjà personnalisé par l'utilisateur.
     */
    private static function inserer(int $associationId, string $code, string $nom): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';
        $insertClause = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

        $safeCode = str_replace("'", "''", $code);
        $safeNom = str_replace("'", "''", $nom);

        DB::statement(<<<SQL
            {$insertClause} INTO familles (association_id, code, nom, created_at, updated_at)
            VALUES ({$associationId}, '{$safeCode}', '{$safeNom}', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            SQL);
    }
}
