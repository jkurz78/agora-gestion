<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercices', function (Blueprint $table): void {
            $table->timestamp('budget_valide_le')->nullable()->after('cloture_par_id');
            $table->foreignId('budget_valide_par_id')
                ->nullable()
                ->after('budget_valide_le')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exercices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('budget_valide_par_id');
            $table->dropColumn('budget_valide_le');
        });
    }
};
