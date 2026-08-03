<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\SystemeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 5 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Seeds the four system accounts into the `comptes` table that was created in
 * Step 3 (2026_05_20_000001_create_comptes_table.php) and enriched in Step 4
 * (2026_05_20_000002_seed_comptes_bancaires_into_comptes.php).
 *
 * Accounts seeded by this migration (spec §3.3):
 *
 *  | numero_pcg | Intitulé             | Classe | Condition             |
 *  |------------|----------------------|--------|-----------------------|
 *  | 411        | Clients              | 4      | Always                |
 *  | 401        | Fournisseurs         | 4      | Always                |
 *  | 5112       | Chèques à encaisser  | 5      | Always                |
 *  | 530        | Caisse (espèces)     | 5      | Always                |
 *
 * 530 was originally conditional on the tenant already having a live espèces
 * transaction; it is now seeded unconditionally like the others (voir
 * 2026_07_28_000001_add_compte_530_system pour le rattrapage des tenants
 * migrés sous l'ancien contrat).
 *
 * All rows are seeded with:
 *   est_systeme = TRUE, lettrable = TRUE, categorie_id = NULL,
 *   actif = TRUE, pour_inscriptions = FALSE, parent_compte_id = NULL,
 *   bank attributes all NULL.
 *
 * The seed logic lives in SystemeSeeder (not inline here) so it can be replayed
 * in tests via replaySystemeSeed() without re-running this migration.
 * The INSERT IGNORE / INSERT OR IGNORE makes each statement idempotent.
 *
 * down() deletes only the rows seeded here, using a narrow filter
 * (est_systeme = TRUE AND numero_pcg IN ('411','401','5112','530'))
 * to avoid touching system comptes that future slices may seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        SystemeSeeder::seed();
    }

    public function down(): void
    {
        DB::table('comptes')
            ->where('est_systeme', true)
            ->whereIn('numero_pcg', ['411', '401', '5112', '530'])
            ->delete();
    }
};
