<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['depenses', 'recettes', 'dons', 'cotisations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('rapprochement_id')
                    ->nullable()
                    ->after('pointe')
                    ->constrained('rapprochements_bancaires')
                    ->nullOnDelete();
            });
        }

        Schema::table('virements_internes', function (Blueprint $table) {
            $table->foreignId('rapprochement_source_id')
                ->nullable()
                ->after('notes')
                ->constrained('rapprochements_bancaires')
                ->nullOnDelete();
            $table->foreignId('rapprochement_destination_id')
                ->nullable()
                ->after('rapprochement_source_id')
                ->constrained('rapprochements_bancaires')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['depenses', 'recettes', 'dons', 'cotisations'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropForeign(['rapprochement_id']);
                $table->dropColumn('rapprochement_id');
            });
        }
        Schema::table('virements_internes', function (Blueprint $table) {
            $table->dropForeign(['rapprochement_source_id']);
            $table->dropForeign(['rapprochement_destination_id']);
            $table->dropColumn(['rapprochement_source_id', 'rapprochement_destination_id']);
        });
    }
};
