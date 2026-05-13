<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->unsignedInteger('helloasso_tier_id')->nullable()->after('helloasso_item_id');
            $table->index('helloasso_tier_id', 'transaction_lignes_helloasso_tier_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->dropIndex('transaction_lignes_helloasso_tier_id_idx');
            $table->dropColumn('helloasso_tier_id');
        });
    }
};
