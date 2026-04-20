<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes_de_frais_lignes', function (Blueprint $table) {
            // Drop FK constraint then the column
            $table->dropForeign(['seance_id']);
            $table->dropColumn('seance_id');
            // Add integer column (1..N), same pattern as TransactionForm
            $table->unsignedSmallInteger('seance')->nullable()->after('operation_id');
        });
    }

    public function down(): void
    {
        Schema::table('notes_de_frais_lignes', function (Blueprint $table) {
            $table->dropColumn('seance');
            $table->foreignId('seance_id')->nullable()->constrained('seances')->nullOnDelete()->after('operation_id');
        });
    }
};
