<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('facture_lignes')
            ->where('type', 'montant_libre')
            ->update(['type' => 'montant_manuel']);
    }

    public function down(): void
    {
        DB::table('facture_lignes')
            ->where('type', 'montant_manuel')
            ->update(['type' => 'montant_libre']);
    }
};
