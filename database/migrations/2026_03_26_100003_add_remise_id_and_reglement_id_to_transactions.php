<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('remise_id')->nullable()->after('rapprochement_id')
                ->constrained('remises_bancaires')->nullOnDelete();
            $table->foreignId('reglement_id')->nullable()->after('remise_id')
                ->constrained('reglements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reglement_id');
            $table->dropConstrainedForeignId('remise_id');
        });
    }
};
