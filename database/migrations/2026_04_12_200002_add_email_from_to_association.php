<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->string('email_from')->nullable()->after('anthropic_api_key');
            $table->string('email_from_name')->nullable()->after('email_from');
        });
    }

    public function down(): void
    {
        Schema::table('association', function (Blueprint $table) {
            $table->dropColumn(['email_from', 'email_from_name']);
        });
    }
};
