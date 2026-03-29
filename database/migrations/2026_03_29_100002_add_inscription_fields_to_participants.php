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
            $table->string('nom_jeune_fille')->nullable()->after('refere_par_id');
            $table->string('nationalite')->nullable()->after('nom_jeune_fille');
            $table->string('adresse_par_nom')->nullable()->after('nationalite');
            $table->string('adresse_par_prenom')->nullable()->after('adresse_par_nom');
            $table->string('adresse_par_telephone')->nullable()->after('adresse_par_prenom');
            $table->string('adresse_par_email')->nullable()->after('adresse_par_telephone');
            $table->string('adresse_par_adresse')->nullable()->after('adresse_par_email');
            $table->string('droit_image')->nullable()->after('adresse_par_adresse');
            $table->string('mode_paiement_choisi')->nullable()->after('droit_image');
            $table->string('moyen_paiement_choisi')->nullable()->after('mode_paiement_choisi');
            $table->boolean('autorisation_contact_medecin')->default(false)->after('moyen_paiement_choisi');
            $table->dateTime('rgpd_accepte_at')->nullable()->after('autorisation_contact_medecin');
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropColumn([
                'nom_jeune_fille', 'nationalite',
                'adresse_par_nom', 'adresse_par_prenom', 'adresse_par_telephone', 'adresse_par_email', 'adresse_par_adresse',
                'droit_image', 'mode_paiement_choisi', 'moyen_paiement_choisi',
                'autorisation_contact_medecin', 'rgpd_accepte_at',
            ]);
        });
    }
};
