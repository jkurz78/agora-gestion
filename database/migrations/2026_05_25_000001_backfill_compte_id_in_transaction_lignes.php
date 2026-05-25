<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\CompteIdBackfiller;
use Illuminate\Database\Migrations\Migration;

/**
 * Step 36 of plans/fondations-partie-double-slice1.md (sous-slice 1d).
 *
 * Backfills transaction_lignes.compte_id from sous_categorie_id via the mapping:
 *   sous_categories.code_cerfa → comptes.numero_pcg (même association_id).
 *
 * The column compte_id was added in Step 6 (migration 2026_05_20_000004)
 * as a nullable FK. This migration populates it for all existing rows that
 * have a sous_categorie_id pointing to a SousCategorie with a code_cerfa
 * that matches a Compte in the same tenant.
 *
 * Orphaned rows (SC with NULL code_cerfa, or code_cerfa without matching
 * compte) are silently skipped — their compte_id remains NULL. A warning
 * is logged via CompteIdBackfiller::up().
 *
 * Idempotent:
 *   - up(): WHERE compte_id IS NULL — running twice is a no-op for already-set rows.
 *   - down(): resets compte_id to NULL for rows matched by the forward join.
 *
 * Note: colonne sous_categorie_id reste présente (drop Step 40 différé, après prod stabilisation).
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = CompteIdBackfiller::up();

        // Surface the count in artisan output
        if ($affected > 0) {
            echo "  [Step 36] Backfill compte_id: {$affected} ligne(s) mise(s) à jour.\n";
        }
    }

    public function down(): void
    {
        CompteIdBackfiller::down();
    }
};
