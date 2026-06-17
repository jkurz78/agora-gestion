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
            $table->unsignedBigInteger('virement_interne_id')->nullable()->after('helloasso_cashout_id');
            $table->foreign('virement_interne_id')
                ->references('id')
                ->on('virements_internes')
                ->nullOnDelete();
            $table->index('virement_interne_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['virement_interne_id']);
            $table->dropIndex(['virement_interne_id']);
            $table->dropColumn('virement_interne_id');
        });
    }
};
