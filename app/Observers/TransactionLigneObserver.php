<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TransactionLigne;
use InvalidArgumentException;

final class TransactionLigneObserver
{
    /**
     * Valide les invariants partie double avant toute persistance Eloquent.
     *
     * Discriminant : la validation ne s'applique qu'aux lignes dont
     * compte_id IS NOT NULL, c'est-à-dire les lignes intentionnellement créées
     * par le nouveau pipeline partie double (Steps 10+).
     *
     * Les lignes legacy slice-0 (sous_categorie_id uniquement, debit/credit
     * aux valeurs par défaut 0, compte_id NULL) sont ignorées jusqu'au
     * backfill du Step 32 qui renseignera compte_id sur toutes ces lignes.
     *
     * Note : cet observer ne se déclenche PAS sur les INSERT SQL bruts
     * (DB::table()->insert(), DB::statement()…) — les événements Eloquent
     * ne couvrent que les opérations passant par l'ORM.
     *
     * Invariants appliqués (partie double uniquement) :
     *   - XOR : debit > 0 XOR credit > 0. Les deux > 0 simultanément est interdit.
     *   - Ni-ni : debit = 0 ET credit = 0 est interdit (ligne vide non significative).
     *   Toute ligne avec compte_id doit avoir soit debit > 0, soit credit > 0.
     */
    public function saving(TransactionLigne $ligne): void
    {
        // Les lignes legacy (compte_id = null) ne sont pas soumises aux invariants
        // partie double tant que le backfill Step 32 ne les a pas migrées.
        if ($ligne->compte_id === null) {
            return;
        }

        $debit = (float) $ligne->debit;
        $credit = (float) $ligne->credit;

        if ($debit > 0 && $credit > 0) {
            throw new InvalidArgumentException(
                "TransactionLigne #{$ligne->id} viole l'invariant partie double : debit et credit > 0 simultanément."
            );
        }

        if ($debit === 0.0 && $credit === 0.0) {
            throw new InvalidArgumentException(
                "TransactionLigne #{$ligne->id} viole l'invariant partie double : ni debit ni credit renseigné."
            );
        }
    }
}
