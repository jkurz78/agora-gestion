<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('type_operations', function (Blueprint $table): void {
            $table->boolean('formulaire_actif')->default(false)->after('attestation_medicale_path');
            $table->boolean('formulaire_prescripteur')->default(false)->after('formulaire_actif');
            $table->boolean('formulaire_parcours_therapeutique')->default(false)->after('formulaire_prescripteur');
            $table->boolean('formulaire_droit_image')->default(false)->after('formulaire_parcours_therapeutique');
            $table->string('formulaire_prescripteur_titre')->nullable()->after('formulaire_droit_image');
            $table->string('formulaire_qualificatif_atelier')->nullable()->after('formulaire_prescripteur_titre');
        });

        // Migrate data from confidentiel
        DB::table('type_operations')->where('confidentiel', true)->update([
            'formulaire_actif' => true,
            'formulaire_prescripteur' => true,
            'formulaire_parcours_therapeutique' => true,
            'formulaire_droit_image' => true,
        ]);

        Schema::table('type_operations', function (Blueprint $table): void {
            $table->dropColumn('confidentiel');
        });
    }

    public function down(): void
    {
        Schema::table('type_operations', function (Blueprint $table): void {
            $table->boolean('confidentiel')->default(false)->after('nombre_seances');
        });

        DB::table('type_operations')->where('formulaire_parcours_therapeutique', true)->update([
            'confidentiel' => true,
        ]);

        Schema::table('type_operations', function (Blueprint $table): void {
            $table->dropColumn([
                'formulaire_actif',
                'formulaire_prescripteur',
                'formulaire_parcours_therapeutique',
                'formulaire_droit_image',
                'formulaire_prescripteur_titre',
                'formulaire_qualificatif_atelier',
            ]);
        });
    }
};
