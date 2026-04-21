<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes_de_frais', function (Blueprint $table): void {
            $table->index(['association_id', 'statut'], 'ndf_asso_statut_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notes_de_frais', function (Blueprint $table): void {
            $table->dropIndex('ndf_asso_statut_idx');
        });
    }
};
