<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->string('adresse_par_etablissement')->nullable()->after('adresse_par_prenom');
            $table->string('adresse_par_code_postal')->nullable()->after('adresse_par_adresse');
            $table->string('adresse_par_ville')->nullable()->after('adresse_par_code_postal');
        });

        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->text('medecin_code_postal')->nullable()->after('medecin_adresse');
            $table->text('medecin_ville')->nullable()->after('medecin_code_postal');
            $table->text('therapeute_code_postal')->nullable()->after('therapeute_adresse');
            $table->text('therapeute_ville')->nullable()->after('therapeute_code_postal');
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropColumn(['adresse_par_etablissement', 'adresse_par_code_postal', 'adresse_par_ville']);
        });

        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->dropColumn(['medecin_code_postal', 'medecin_ville', 'therapeute_code_postal', 'therapeute_ville']);
        });
    }
};
