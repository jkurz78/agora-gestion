<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Adhesion;
use App\Models\RecuFiscalEmis;
use App\Services\RecuFiscalService;

final class AdhesionRecuFiscalObserver
{
    public function deleted(Adhesion $adhesion): void
    {
        if ($adhesion->transaction_id === null) {
            return;
        }

        try {
            $service = app(RecuFiscalService::class);

            // Prédicat ligne parente compatible manuel + HelloAsso (709A, T5).
            $ligne = $adhesion->transaction->lignes()
                ->whereNull('deleted_at')
                ->where(function ($query): void {
                    $query->whereNull('helloasso_item_id')
                        ->orWhere('helloasso_line_key', 'parent');
                })
                ->first();

            if ($ligne === null) {
                return;
            }

            $recu = RecuFiscalEmis::where('transaction_ligne_id', $ligne->id)
                ->whereNull('annule_at')
                ->first();

            if ($recu !== null) {
                $service->annuler($recu, 'Adhésion supprimée');
            }
        } catch (\Throwable) {
            // L'observer ne doit jamais bloquer la suppression de l'adhésion
        }
    }
}
