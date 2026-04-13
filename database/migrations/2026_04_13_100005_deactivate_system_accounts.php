<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE comptes_bancaires
            SET actif_recettes_depenses = 0
            WHERE est_systeme = 1
        ');
    }

    public function down(): void
    {
        DB::statement('
            UPDATE comptes_bancaires
            SET actif_recettes_depenses = 1
            WHERE est_systeme = 1
        ');
    }
};
