<?php

use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot: reroute existing HelloAsso transactions paid by check or cash
 * from the HelloAsso account to "Remises en banque" (system account).
 * These payments are physically in hand, not on HelloAsso's bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        $parametres = HelloAssoParametres::first();
        if ($parametres === null) {
            return;
        }

        $compteHelloasso = $parametres->compte_helloasso_id;
        $compteRemise = CompteBancaire::where('nom', 'Remises en banque')
            ->where('est_systeme', true)
            ->value('id');

        if ($compteRemise === null || $compteHelloasso === null) {
            return;
        }

        $updated = DB::table('transactions')
            ->where('compte_id', $compteHelloasso)
            ->whereNotNull('helloasso_order_id')
            ->whereIn('mode_paiement', ['chèque', 'espèces'])
            ->whereNull('rapprochement_id')
            ->whereNull('deleted_at')
            ->update(['compte_id' => $compteRemise]);

        if ($updated > 0) {
            logger()->info("HelloAsso reroute: {$updated} transaction(s) chèque/espèces déplacée(s) vers Remises en banque.");
        }
    }

    public function down(): void
    {
        // Irréversible par migration — les transactions peuvent être corrigées manuellement
    }
};
