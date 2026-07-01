<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $colonnes = ['email', 'nom', 'prenom', 'telephone', 'adresse_ligne1', 'ville'];
        $isMysql = DB::getDriverName() === 'mysql';

        foreach ($colonnes as $col) {
            if ($isMysql) {
                DB::statement("UPDATE `tiers` SET `{$col}` = TRIM(`{$col}`) WHERE `{$col}` != TRIM(`{$col}`)");
            }
        }
    }
};
