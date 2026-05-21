<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Step 7 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Adds two columns to `transactions` per spec §2.3:
 *
 *   - `equilibree` (BOOLEAN NOT NULL DEFAULT FALSE): runtime invariant flag
 *     indicating that ∑débit = ∑crédit on the transaction lines. Calculated
 *     and enforced at save time in a later step (no backfill here).
 *
 *   - `type_ecriture` (ENUM NOT NULL DEFAULT 'normale'): classifies the
 *     journal-entry kind. Values 'an' (à-nouveau) and 'od' (opération diverse)
 *     are used from slice 2 onwards. 'extourne' aligns with the existing
 *     Extourne mechanism.
 *
 * Legacy columns `type`, `compte_id`, `tiers_id`, and `remise_id` are
 * intentionally preserved for compatibility and will be dropped in Step 40.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('equilibree')->default(false)->after('montant_total');
            $table->enum('type_ecriture', ['normale', 'an', 'od', 'extourne'])
                ->default('normale')
                ->after('equilibree');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['type_ecriture', 'equilibree']);
        });
    }
};
