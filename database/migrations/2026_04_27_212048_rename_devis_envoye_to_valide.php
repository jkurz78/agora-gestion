<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devis')->where('statut', 'envoye')->update(['statut' => 'valide']);
    }

    public function down(): void
    {
        DB::table('devis')->where('statut', 'valide')->update(['statut' => 'envoye']);
    }
};
