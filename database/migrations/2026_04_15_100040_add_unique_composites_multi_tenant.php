<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace single-column unique constraints with composites prefixed by
 * association_id, so that data is unique WITHIN a tenant but CAN repeat
 * across tenants.
 *
 * Tables affected:
 *   - exercices      : unique(annee)         → unique(association_id, annee)
 *   - type_operations: unique(nom)           → unique(association_id, nom)
 *
 * Tables checked but skipped:
 *   - sequences      : no association_id column (not a tenant table yet)
 *   - categories     : nom/code was never declared unique
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── exercices ────────────────────────────────────────────────────────
        Schema::table('exercices', function (Blueprint $t): void {
            // Drop existing unique index on annee (name: exercices_annee_unique)
            try {
                $t->dropUnique(['annee']);
            } catch (Throwable) {
                // Already absent — safe to continue
            }
            $t->unique(['association_id', 'annee']);
        });

        // ── type_operations ──────────────────────────────────────────────────
        Schema::table('type_operations', function (Blueprint $t): void {
            // Drop existing unique index on nom (name: type_operations_nom_unique)
            try {
                $t->dropUnique(['nom']);
            } catch (Throwable) {
                // Already absent — safe to continue
            }
            $t->unique(['association_id', 'nom']);
        });

        // ── sequences ────────────────────────────────────────────────────────
        // sequences never received association_id in the S1 multi-tenancy
        // migrations — skip entirely.
    }

    public function down(): void
    {
        // ── exercices ────────────────────────────────────────────────────────
        Schema::table('exercices', function (Blueprint $t): void {
            $t->dropUnique(['association_id', 'annee']);
            $t->unique('annee');
        });

        // ── type_operations ──────────────────────────────────────────────────
        Schema::table('type_operations', function (Blueprint $t): void {
            $t->dropUnique(['association_id', 'nom']);
            $t->unique('nom');
        });
    }
};
