<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->after('nom');
            $table->unsignedTinyInteger('exercice_mois_debut')->default(9)->after('slug');
            $table->string('statut', 20)->default('actif')->after('exercice_mois_debut');
            $table->timestamp('wizard_completed_at')->nullable()->after('statut');
        });

        // Backfill slug pour l'asso existante SVS : "svs" ou slug depuis nom
        $existing = DB::table('association')->get();
        foreach ($existing as $row) {
            DB::table('association')
                ->where('id', $row->id)
                ->update([
                    'slug' => Str::slug($row->nom ?: "asso-{$row->id}"),
                    'wizard_completed_at' => now(), // assos existantes déjà opérationnelles
                ]);
        }

        Schema::table('association', function (Blueprint $table) {
            $table->string('slug', 80)->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'exercice_mois_debut', 'statut', 'wizard_completed_at']);
        });
    }
};
