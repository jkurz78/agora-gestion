<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // Backfill slug pour les associations existantes
        $existing = DB::table('association')->get();
        foreach ($existing as $row) {
            $base = Str::slug($row->nom ?: "asso-{$row->id}");
            $slug = $base;
            $n = 1;
            while (DB::table('association')->where('slug', $slug)->where('id', '!=', $row->id)->exists()) {
                $slug = $base.'-'.$n++;
            }
            DB::table('association')->where('id', $row->id)->update([
                'slug' => $slug,
                'wizard_completed_at' => now(),
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
