<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->foreignId('saisi_par')->nullable()->after('motif_gratuite')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('adhesions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('saisi_par');
        });
    }
};
