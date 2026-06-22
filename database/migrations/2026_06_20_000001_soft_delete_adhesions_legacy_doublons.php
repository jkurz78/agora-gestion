<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nettoyage du doublon d'adhésion provoqué par le bug d'idempotence de
 * AdhesionService::creerDepuisTransaction (corrigé dans le même lot : idempotence
 * désormais par transaction_id).
 *
 * Symptôme : une adhésion saisie via le wizard en mode durée (exercice=NULL) puis
 * « marquée reçue » se voyait dupliquer par l'observer, qui recréait une adhésion
 * « Adhésion legacy » (formule NULL, exercice calculé) sur la MÊME transaction.
 *
 * Ce nettoyage soft-delete uniquement la ligne « Adhésion legacy » (signature de
 * l'observer) lorsqu'une adhésion porteuse d'une formule existe sur la même
 * transaction. Réversible (SoftDeletes) — restaurable via withTrashed()->restore().
 */
return new class extends Migration
{
    public function up(): void
    {
        // Transactions portant 2+ adhésions actives (signature potentielle du doublon).
        $txMultiples = DB::table('adhesions')
            ->whereNull('deleted_at')
            ->whereNotNull('transaction_id')
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('transaction_id');

        if ($txMultiples->isEmpty()) {
            return;
        }

        $aSupprimer = [];

        foreach ($txMultiples as $transactionId) {
            $adhesions = DB::table('adhesions')
                ->whereNull('deleted_at')
                ->where('transaction_id', $transactionId)
                ->get();

            // On ne nettoie que si une adhésion porteuse d'une formule existe
            // (= l'intention de saisie à conserver). Sinon, on ne touche à rien.
            $aFormule = $adhesions->first(fn ($a) => $a->formule_adhesion_id !== null);

            if ($aFormule === null) {
                continue;
            }

            foreach ($adhesions as $adhesion) {
                if ($adhesion->formule_adhesion_id === null && $adhesion->label_formule === 'Adhésion legacy') {
                    $aSupprimer[] = $adhesion->id;
                }
            }
        }

        if ($aSupprimer === []) {
            return;
        }

        DB::table('adhesions')
            ->whereIn('id', $aSupprimer)
            ->update(['deleted_at' => now()]);

        Log::info('[migration] Adhésions « legacy » en doublon soft-deletées', [
            'count' => count($aSupprimer),
            'ids' => $aSupprimer,
        ]);
    }

    public function down(): void
    {
        // Non réversible automatiquement : impossible de distinguer ces soft-deletes
        // d'autres annulations d'adhésion légitimes. Les lignes restent restaurables
        // manuellement (Adhesion::withTrashed()->find($id)->restore()).
    }
};
