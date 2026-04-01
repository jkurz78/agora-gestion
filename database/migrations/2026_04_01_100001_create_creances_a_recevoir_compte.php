<?php

// database/migrations/2026_04_01_100001_create_creances_a_recevoir_compte.php
declare(strict_types=1);

use App\Models\CompteBancaire;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        CompteBancaire::create([
            'nom' => 'Créances à recevoir',
            'iban' => '',
            'solde_initial' => 0,
            'date_solde_initial' => now()->toDateString(),
            'actif_recettes_depenses' => true,
            'actif_dons_cotisations' => false,
            'est_systeme' => true,
        ]);
    }

    public function down(): void
    {
        CompteBancaire::where('nom', 'Créances à recevoir')
            ->where('est_systeme', true)
            ->delete();
    }
};
