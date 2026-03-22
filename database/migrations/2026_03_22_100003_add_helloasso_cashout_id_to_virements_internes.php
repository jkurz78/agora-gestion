<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virements_internes', function (Blueprint $table) {
            $table->unsignedBigInteger('helloasso_cashout_id')->nullable()->unique()->after('numero_piece');
        });
    }

    public function down(): void
    {
        Schema::table('virements_internes', function (Blueprint $table) {
            $table->dropColumn('helloasso_cashout_id');
        });
    }
};
