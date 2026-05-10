<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL: élargit l'enum pour accepter 'illimite'. SQLite n'a pas de type ENUM natif, rien à faire.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE formules_adhesion MODIFY COLUMN mode ENUM('exercice', 'duree', 'illimite') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE formules_adhesion MODIFY COLUMN mode ENUM('exercice', 'duree') NOT NULL");
        }
    }
};
