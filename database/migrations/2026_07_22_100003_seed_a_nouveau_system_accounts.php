<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\SystemeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(SystemeSeeder::unconditionalSql('102', 'Fonds associatifs sans droit de reprise', 1, false));
        DB::statement(SystemeSeeder::unconditionalSql('120', 'Résultat de l’exercice (excédent)', 1, false));
        DB::statement(SystemeSeeder::unconditionalSql('129', 'Résultat de l’exercice (déficit)', 1, false));
    }

    public function down(): void
    {
        DB::table('comptes')
            ->whereIn('numero_pcg', ['102', '120', '129'])
            ->where('est_systeme', true)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('transaction_lignes')
                    ->whereColumn('transaction_lignes.compte_id', 'comptes.id');
            })
            ->delete();
    }
};
