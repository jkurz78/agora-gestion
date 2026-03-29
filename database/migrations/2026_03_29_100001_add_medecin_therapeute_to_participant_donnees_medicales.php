<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->text('medecin_nom')->nullable()->after('notes');
            $table->text('medecin_prenom')->nullable()->after('medecin_nom');
            $table->text('medecin_telephone')->nullable()->after('medecin_prenom');
            $table->text('medecin_email')->nullable()->after('medecin_telephone');
            $table->text('medecin_adresse')->nullable()->after('medecin_email');
            $table->text('therapeute_nom')->nullable()->after('medecin_adresse');
            $table->text('therapeute_prenom')->nullable()->after('therapeute_nom');
            $table->text('therapeute_telephone')->nullable()->after('therapeute_prenom');
            $table->text('therapeute_email')->nullable()->after('therapeute_telephone');
            $table->text('therapeute_adresse')->nullable()->after('therapeute_email');
        });
    }

    public function down(): void
    {
        Schema::table('participant_donnees_medicales', function (Blueprint $table): void {
            $table->dropColumn([
                'medecin_nom', 'medecin_prenom', 'medecin_telephone', 'medecin_email', 'medecin_adresse',
                'therapeute_nom', 'therapeute_prenom', 'therapeute_telephone', 'therapeute_email', 'therapeute_adresse',
            ]);
        });
    }
};
