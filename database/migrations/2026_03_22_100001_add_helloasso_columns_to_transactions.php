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
            $table->unsignedBigInteger('helloasso_order_id')->nullable()->after('numero_piece');
            $table->unsignedBigInteger('helloasso_cashout_id')->nullable()->index()->after('helloasso_order_id');
            $table->unique(['helloasso_order_id', 'tiers_id'], 'transactions_ha_order_tiers_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_ha_order_tiers_unique');
            $table->dropIndex(['helloasso_cashout_id']);
            $table->dropColumn(['helloasso_order_id', 'helloasso_cashout_id']);
        });
    }
};
