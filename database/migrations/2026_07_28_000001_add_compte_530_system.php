<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\SystemeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute le compte système 530 (Caisse — espèces) à toutes les associations.
 *
 * Le seed initial (2026_05_20_000003) ne créait le 530 que pour les tenants
 * ayant déjà une transaction non supprimée en espèces. L'existence du compte
 * dépendait donc de l'ordre : une transaction passée en espèces après le seed
 * ne trouvait aucun 530 et CompteTresorerieResolver levait ModelNotFound.
 *
 * SystemeSeeder seede désormais le 530 comme les autres comptes système ; cette
 * migration rattrape les tenants déjà migrés. INSERT IGNORE / INSERT OR IGNORE
 * → idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(SystemeSeeder::unconditionalSql(
            '530',
            'Caisse (espèces)',
            5,
        ));
    }

    public function down(): void
    {
        // Pas de down() destructif : le 530 peut désormais porter des écritures
        // (portage espèces) chez des tenants qui n'en avaient pas avant.
    }
};
