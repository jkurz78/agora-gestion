<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['recettes', 'depenses', 'dons', 'cotisations', 'virements_internes'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('numero_piece', 20)->nullable()->unique()->after('id'); // unique par table ; l'unicité globale est garantie par la table sequences
            });
        }
    }

    public function down(): void
    {
        $tables = ['recettes', 'depenses', 'dons', 'cotisations', 'virements_internes'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('numero_piece');
            });
        }
    }
};
