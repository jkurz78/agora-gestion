<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('piece_jointe_path', 500)->nullable()->after('numero_piece');
            $table->string('piece_jointe_nom', 255)->nullable()->after('piece_jointe_path');
            $table->string('piece_jointe_mime', 100)->nullable()->after('piece_jointe_nom');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['piece_jointe_path', 'piece_jointe_nom', 'piece_jointe_mime']);
        });
    }
};
