<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deactivate the two legacy system accounts ("Créances à recevoir",
 * "Remises en banque"). They no longer participate in any active flow
 * but historical transactions may still reference them, so we mark
 * them inactive rather than soft-delete to preserve FK integrity.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = ['Créances à recevoir', 'Remises en banque'];

        $count = DB::table('comptes_bancaires')
            ->whereIn('nom', $names)
            ->where('est_systeme', true)
            ->update([
                'actif_recettes_depenses' => false,
                'est_systeme' => false,
                'updated_at' => now(),
            ]);

        if ($count > 0) {
            logger()->info("Legacy system accounts deactivated: {$count} row(s).");
        }
    }

    public function down(): void
    {
        $names = ['Créances à recevoir', 'Remises en banque'];

        DB::table('comptes_bancaires')
            ->whereIn('nom', $names)
            ->update([
                'est_systeme' => true,
                'updated_at' => now(),
            ]);
    }
};
