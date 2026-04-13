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
        $hasFk = DB::select("
            SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'transactions'
              AND COLUMN_NAME = 'compte_origine_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL
              AND CONSTRAINT_SCHEMA = DATABASE()
        ");

        Schema::table('transactions', function (Blueprint $table) use ($hasFk): void {
            if (! empty($hasFk)) {
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
