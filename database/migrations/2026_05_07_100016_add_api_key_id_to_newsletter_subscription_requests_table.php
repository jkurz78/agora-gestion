<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_subscription_requests', function (Blueprint $table) {
            $table->foreignId('api_key_id')
                ->nullable()
                ->after('user_agent')
                ->constrained('association_api_keys')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_subscription_requests', function (Blueprint $table) {
            $table->dropForeign(['api_key_id']);
            $table->dropColumn('api_key_id');
        });
    }
};
