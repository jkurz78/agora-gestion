<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('tracking_token', 32)->nullable()->unique()->after('campagne_id');
        });

        Schema::create('email_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_log_id')->constrained('email_logs')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('opened_at');

            $table->index('email_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_opens');

        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropColumn('tracking_token');
        });
    }
};
