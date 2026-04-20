<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes_de_frais_lignes', function (Blueprint $table) {
            $table->string('type', 20)->default('standard')->after('seance_id');
            $table->json('metadata')->nullable()->after('piece_jointe_path');
        });
    }

    public function down(): void
    {
        Schema::table('notes_de_frais_lignes', function (Blueprint $table) {
            $table->dropColumn(['type', 'metadata']);
        });
    }
};
