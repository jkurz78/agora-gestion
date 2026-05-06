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
            $table->timestamp('ignored_at')->nullable()->after('tiers_id');
            $table->timestamp('desinscription_traitee_at')->nullable()->after('ignored_at');
            $table->enum('desinscription_action', ['optout', 'deleted', 'noop'])
                ->nullable()
                ->after('desinscription_traitee_at');
            $table->foreignId('processed_by_user_id')
                ->nullable()
                ->after('desinscription_action')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(
                ['association_id', 'status', 'tiers_id', 'ignored_at'],
                'idx_newsletter_inbox_inscriptions',
            );
            $table->index(
                ['association_id', 'status', 'desinscription_traitee_at'],
                'idx_newsletter_inbox_desinscriptions',
            );
        });
    }

    public function down(): void
    {
        Schema::table('newsletter_subscription_requests', function (Blueprint $table) {
            $table->dropIndex('idx_newsletter_inbox_inscriptions');
            $table->dropIndex('idx_newsletter_inbox_desinscriptions');
            $table->dropForeign(['processed_by_user_id']);
            $table->dropColumn([
                'ignored_at',
                'desinscription_traitee_at',
                'desinscription_action',
                'processed_by_user_id',
            ]);
        });
    }
};
