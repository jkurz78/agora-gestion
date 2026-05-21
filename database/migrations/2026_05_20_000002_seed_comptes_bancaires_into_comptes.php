<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\BancairesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 4 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Seeds classe-5 sous-comptes (5121, 5122, 5123…) into the `comptes` table
 * that was created in Step 3 (2026_05_20_000001_create_comptes_table.php).
 *
 * One compte is created per compte_bancaire, with:
 *  - numero_pcg = '5121', '5122'… (per tenant, ordered by comptes_bancaires.id ASC)
 *  - intitule    = comptes_bancaires.nom
 *  - classe      = 5
 *  - est_systeme = TRUE, lettrable = FALSE (rappro bancaire flag, not lettrage)
 *  - Bank attributes (iban, bic, domiciliation, solde_initial, date_solde_initial)
 *    copied verbatim from comptes_bancaires.
 *
 * Filter decision: all comptes_bancaires rows are included (no deleted_at filter).
 * The comptes_bancaires table does not use SoftDeletes (no deleted_at column).
 * Inactive banks (actif_recettes_depenses = false) are still seeded: that flag
 * gates manual-entry UI, not ledger existence. A deactivation step for the
 * corresponding compte can ride on a later migration if needed.
 *
 * The seed logic lives in BancairesSeeder (not inline here) so it can be
 * replayed in tests via replayBancairesSeed() without re-running this migration.
 * The INSERT IGNORE / INSERT OR IGNORE makes it idempotent on re-run.
 *
 * down() deletes only the classe-5 comptes whose numero_pcg matches the 512x
 * pattern, leaving class 6/7 comptes (seeded in Step 3) untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        BancairesSeeder::seed();
    }

    public function down(): void
    {
        DB::table('comptes')
            ->where('classe', 5)
            ->where('numero_pcg', 'LIKE', '512_%')
            ->delete();
    }
};
