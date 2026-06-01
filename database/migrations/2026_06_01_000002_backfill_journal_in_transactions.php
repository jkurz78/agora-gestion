<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\JournalBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1 « journal de banque ». Peuple transactions.journal sur l'existant
 * puis passe la colonne NOT NULL (MySQL). Voir docs/specs/2026-06-01-journal-de-banque-slice1.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        JournalBackfiller::run();

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY journal ENUM('vente','achat','banque','od') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY journal ENUM('vente','achat','banque','od') NULL");
        }
    }
};
