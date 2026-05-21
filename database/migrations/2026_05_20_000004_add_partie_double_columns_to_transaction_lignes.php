<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Step 6 of plans/fondations-partie-double-slice1.md (sous-slice 1a).
 *
 * Adds partie-double columns to `transaction_lignes` as per spec §2.2.
 * No backfill is performed here — that is Step 32.
 *
 * New columns:
 *   - compte_id          FK → comptes (nullable, nullOnDelete)
 *   - debit              DECIMAL(12,2) NOT NULL DEFAULT 0
 *   - credit             DECIMAL(12,2) NOT NULL DEFAULT 0
 *   - tiers_id           FK → tiers (nullable, nullOnDelete)
 *   - lettrage_code      VARCHAR(20) nullable
 *   - libelle            VARCHAR(255) nullable
 *
 * Indexes added:
 *   - (compte_id, tiers_id, lettrage_code)
 *   - (lettrage_code)
 *   - (compte_id, tiers_id)
 *
 * Existing columns `sous_categorie_id` and `montant` are untouched —
 * they will be dropped in Step 40 after prod stabilisation.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->foreignId('compte_id')
                ->nullable()
                ->constrained('comptes')
                ->nullOnDelete()
                ->after('sous_categorie_id');

            $table->decimal('debit', 12, 2)
                ->default(0)
                ->after('compte_id');

            $table->decimal('credit', 12, 2)
                ->default(0)
                ->after('debit');

            $table->foreignId('tiers_id')
                ->nullable()
                ->constrained('tiers')
                ->nullOnDelete()
                ->after('credit');

            $table->string('lettrage_code', 20)
                ->nullable()
                ->after('tiers_id');

            $table->string('libelle', 255)
                ->nullable()
                ->after('lettrage_code');

            // Three indexes per spec §2.2
            $table->index(['compte_id', 'tiers_id', 'lettrage_code']);
            $table->index(['lettrage_code']);
            $table->index(['compte_id', 'tiers_id']);
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            // Drop indexes in reverse order before dropping columns
            $table->dropIndex(['compte_id', 'tiers_id']);
            $table->dropIndex(['lettrage_code']);
            $table->dropIndex(['compte_id', 'tiers_id', 'lettrage_code']);

            // Drop foreign key constraints then columns
            $table->dropConstrainedForeignId('compte_id');
            $table->dropConstrainedForeignId('tiers_id');

            $table->dropColumn(['debit', 'credit', 'lettrage_code', 'libelle']);
        });
    }
};
