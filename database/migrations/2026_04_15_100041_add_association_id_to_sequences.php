<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap fix from Task 13: sequences table was missed in the S1 multi-tenancy
 * group migrations. Adds association_id, backfills from the first association,
 * flips NOT NULL, and replaces unique(exercice) with unique(association_id, exercice).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sequences')) {
            return;
        }

        // 1. Add nullable column
        Schema::table('sequences', function (Blueprint $t): void {
            $t->foreignId('association_id')
                ->nullable()
                ->after('id')
                ->index()
                ->constrained('association')
                ->cascadeOnDelete();
        });

        // 2. Backfill — assign all existing rows to the first association
        $firstId = DB::table('association')->orderBy('id')->value('id');
        if ($firstId) {
            DB::table('sequences')->whereNull('association_id')->update(['association_id' => $firstId]);
        }

        // 3. Flip NOT NULL
        Schema::table('sequences', function (Blueprint $t): void {
            $t->foreignId('association_id')->nullable(false)->change();
        });

        // 4. Replace single-column unique on exercice with composite (association_id, exercice)
        // Drop in a separate closure to avoid batching with the add — each
        // Schema::table() emits its own ALTER TABLE so a failing DROP won't
        // prevent the ADD UNIQUE below.
        $indexes = collect(Schema::getIndexes('sequences'));
        $exerciceUniqueExists = $indexes->contains(
            fn ($i) => $i['unique'] && $i['columns'] === ['exercice']
        );

        if ($exerciceUniqueExists) {
            Schema::table('sequences', function (Blueprint $t): void {
                $t->dropUnique(['exercice']);
            });
        }

        Schema::table('sequences', function (Blueprint $t): void {
            $t->unique(['association_id', 'exercice']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sequences')) {
            return;
        }

        Schema::table('sequences', function (Blueprint $t): void {
            try {
                $t->dropUnique(['association_id', 'exercice']);
            } catch (Throwable) {}
            $t->unique(['exercice']);
            $t->dropConstrainedForeignId('association_id');
        });
    }
};
