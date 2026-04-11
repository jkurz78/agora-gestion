<?php

use App\Models\CompteBancaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot: reroute existing HelloAsso chèque/espèces transactions
 * to "Créances à recevoir" so they can go through remise bancaire.
 * Supersedes 2026_04_10_000002 which routed them to "Remises en banque".
 */
return new class extends Migration
{
    public function up(): void
    {
        $compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')
            ->where('est_systeme', true)
            ->value('id');

        if ($compteCreances === null) {
            return;
        }

        $updated = DB::table('transactions')
            ->whereNotNull('helloasso_order_id')
            ->whereIn('mode_paiement', ['chèque', 'espèces'])
            ->where('compte_id', '!=', $compteCreances)
            ->whereNull('rapprochement_id')
            ->whereNull('remise_id')
            ->whereNull('deleted_at')
            ->update(['compte_id' => $compteCreances]);

        if ($updated > 0) {
            logger()->info("HelloAsso reroute: {$updated} transaction(s) chèque/espèces déplacée(s) vers Créances à recevoir.");
        }
    }

    public function down(): void
    {
        // Irréversible par migration
    }
};
