<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagnes_email', function (Blueprint $table) {
            $table->json('pieces_jointes')->nullable()->after('corps');
        });
    }

    public function down(): void
    {
        Schema::table('campagnes_email', function (Blueprint $table) {
            $table->dropColumn('pieces_jointes');
        });
    }
};
