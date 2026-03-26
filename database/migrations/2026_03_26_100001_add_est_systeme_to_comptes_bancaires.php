<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->boolean('est_systeme')->default(false)->after('actif_dons_cotisations');
        });

        CompteBancaire::create([
            'nom' => 'Remises en banque',
            'iban' => '',
            'solde_initial' => 0,
            'date_solde_initial' => now()->toDateString(),
            'actif_recettes_depenses' => false,
            'actif_dons_cotisations' => false,
            'est_systeme' => true,
        ]);
    }

    public function down(): void
    {
        CompteBancaire::where('est_systeme', true)->delete();

        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->dropColumn('est_systeme');
        });
    }
};
