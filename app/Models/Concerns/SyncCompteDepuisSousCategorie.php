<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Double écriture transitoire DC-3 (programme dissolution sous_categories → comptes).
 *
 * Tant que les écrans écrivent encore sous_categorie_id (bascule en DC-8), ce hook
 * remplit compte_id automatiquement au saving via le mapping
 * code_cerfa = numero_pcg (même association). Il ne remplace JAMAIS un compte_id
 * déjà posé, et se resynchronise si sous_categorie_id change.
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

            if ($model->sous_categorie_id === null || $model->compte_id !== null) {
                return;
            }

            $model->compte_id = self::resoudreCompteId((int) $model->sous_categorie_id);
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
}
