<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->boolean('email_optout')->default(false)->after('est_helloasso');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table) {
            $table->dropColumn('email_optout');
        });
    }
};
