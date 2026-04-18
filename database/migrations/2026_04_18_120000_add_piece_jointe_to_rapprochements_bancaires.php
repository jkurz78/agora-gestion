<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rapprochements_bancaires', function (Blueprint $table): void {
            $table->string('piece_jointe_path')->nullable()->after('verrouille_at');
            $table->string('piece_jointe_nom')->nullable()->after('piece_jointe_path');
            $table->string('piece_jointe_mime', 100)->nullable()->after('piece_jointe_nom');
        });
    }

    public function down(): void
    {
        Schema::table('rapprochements_bancaires', function (Blueprint $table): void {
            $table->dropColumn(['piece_jointe_path', 'piece_jointe_nom', 'piece_jointe_mime']);
        });
    }
};
