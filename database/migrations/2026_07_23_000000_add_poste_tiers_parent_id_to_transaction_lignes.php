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
            $table->foreignId('poste_tiers_parent_id')
                ->nullable()
                ->after('lettrage_code')
                ->constrained('transaction_lignes')
                ->nullOnDelete();
            $table->index(
                ['transaction_id', 'poste_tiers_parent_id', 'lettrage_code'],
                'tl_poste_tiers_ouvert_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->dropIndex('tl_poste_tiers_ouvert_idx');
            $table->dropConstrainedForeignId('poste_tiers_parent_id');
        });
    }
};
