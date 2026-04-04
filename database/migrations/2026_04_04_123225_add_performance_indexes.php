<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->index(['transaction_id', 'sous_categorie_id'], 'tl_tx_sc_idx');
            $table->index('operation_id', 'tl_operation_idx');
        });

        Schema::table('transaction_ligne_affectations', function (Blueprint $table) {
            $table->index('transaction_ligne_id', 'tla_tl_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table) {
            $table->dropIndex('tl_tx_sc_idx');
            $table->dropIndex('tl_operation_idx');
        });

        Schema::table('transaction_ligne_affectations', function (Blueprint $table) {
            $table->dropIndex('tla_tl_idx');
        });
    }
};
