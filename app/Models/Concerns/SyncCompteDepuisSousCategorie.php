<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Double écriture transitoire DC-3/DC-8 (programme dissolution sous_categories → comptes).
 *
 * Tant que les écrans peuvent encore écrire sous_categorie_id OU compte_id
 * séparément (bascule en DC-8), ce hook synchronise les deux colonnes au
 * saving dans les DEUX sens, via le même mapping bijectif
 * code_cerfa = numero_pcg (même association) :
 *   - sous_categorie_id renseigné, compte_id absent → dérive compte_id ;
 *   - compte_id renseigné, sous_categorie_id absent → dérive sous_categorie_id
 *     (lookup inverse).
 *
 * Il ne remplace JAMAIS une colonne cible déjà posée explicitement, et se
 * resynchronise (colonne cible remise à null pour re-dérivation) quand la
 * colonne source change sur un enregistrement existant sans que l'autre
 * colonne n'ait été touchée en même temps — symétrique dans les deux sens.
 *
 * ⚠ Ne PAS poser sur TransactionLigne : son compte_id est géré par le pipeline PD
 * (EcritureGenerator / TransactionService) et un remplissage au saving déclencherait
 * l'invariant XOR de TransactionLigneObserver sur les lignes legacy à debit/credit 0.
 *
 * Échafaudage : disparaît en DC-10 avec la colonne sous_categorie_id.
 */
trait SyncCompteDepuisSousCategorie
{
    public static function bootSyncCompteDepuisSousCategorie(): void
    {
        static::saving(function ($model): void {
            // Resync si la sous-catégorie a changé (édition d'une ligne existante)
            if ($model->isDirty('sous_categorie_id') && ! $model->isDirty('compte_id')) {
                $model->compte_id = null;
            }

            // Resync symétrique si compte_id a changé (édition côté compte)
            if ($model->isDirty('compte_id') && ! $model->isDirty('sous_categorie_id')) {
                $model->sous_categorie_id = null;
            }

            if ($model->sous_categorie_id !== null && $model->compte_id === null) {
                $model->compte_id = self::resoudreCompteId((int) $model->sous_categorie_id);

                return;
            }

            if ($model->compte_id !== null && $model->sous_categorie_id === null) {
                $model->sous_categorie_id = self::resoudreSousCategorieId((int) $model->compte_id);
            }
        });
    }

    /** Mapping sous_categorie → compte (code_cerfa = numero_pcg, même association). */
    private static function resoudreCompteId(int $sousCategorieId): ?int
    {
        $sc = DB::table('sous_categories')
            ->where('id', $sousCategorieId)
            ->first(['association_id', 'code_cerfa']);

        if ($sc === null || $sc->code_cerfa === null) {
            return null;
        }

        $compteId = DB::table('comptes')
            ->where('association_id', (int) $sc->association_id)
            ->where('numero_pcg', (string) $sc->code_cerfa)
            ->value('id');

        return $compteId !== null ? (int) $compteId : null;
    }

    /** Mapping inverse compte → sous_categorie (numero_pcg = code_cerfa, même association). */
    private static function resoudreSousCategorieId(int $compteId): ?int
    {
        $compte = DB::table('comptes')
            ->where('id', $compteId)
            ->first(['association_id', 'numero_pcg']);

        if ($compte === null || $compte->numero_pcg === null) {
            return null;
        }

        $sousCategorieId = DB::table('sous_categories')
            ->where('association_id', (int) $compte->association_id)
            ->where('code_cerfa', (string) $compte->numero_pcg)
            ->value('id');

        return $sousCategorieId !== null ? (int) $sousCategorieId : null;
    }
}
