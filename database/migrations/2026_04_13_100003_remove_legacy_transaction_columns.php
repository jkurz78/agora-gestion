<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('transactions', 'pointe')) {
                $table->dropColumn('pointe');
            }
        });

        // Drop FK only if it still exists (may have been removed in a partial run)
        // Use Schema::getForeignKeys() for cross-DB compatibility (MySQL + SQLite)
        $hasFk = Schema::hasColumn('transactions', 'compte_origine_id')
            && collect(Schema::getForeignKeys('transactions'))
                ->contains(fn ($fk) => in_array('compte_origine_id', $fk['columns']));

        Schema::table('transactions', function (Blueprint $table) use ($hasFk): void {
            if ($hasFk) {
                $table->dropForeign(['compte_origine_id']);
            }
            $colsToDrop = array_filter(
                ['date_reglement', 'reference_reglement', 'compte_origine_id'],
                fn (string $col) => Schema::hasColumn('transactions', $col)
            );
            if (! empty($colsToDrop)) {
                $table->dropColumn(array_values($colsToDrop));
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->boolean('pointe')->default(false)->after('statut_reglement');
            $table->date('date_reglement')->nullable()->after('helloasso_payment_id');
            $table->string('reference_reglement')->nullable()->after('date_reglement');
            $table->unsignedBigInteger('compte_origine_id')->nullable()->after('reference_reglement');
        });
    }
};
