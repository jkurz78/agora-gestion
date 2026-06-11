<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\SystemeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute le compte système 467 (Autres comptes débiteurs ou créditeurs)
 * pour toutes les associations existantes.
 *
 * Ce compte sert de clearing interne pour les compensations d'abandon
 * de créance (NDF). INSERT IGNORE / INSERT OR IGNORE → idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(SystemeSeeder::unconditionalSql(
            '467',
            'Autres comptes débiteurs ou créditeurs',
            4,
        ));
    }

    public function down(): void
    {
        DB::table('comptes')
            ->where('numero_pcg', '467')
            ->where('est_systeme', true)
            ->delete();
    }
};
